<?php
/**
 * SMTP sink for the tests: accepts messages and stores them as .eml files.
 *   php smtp-sink.php <port> <out dir> [limit]
 * - RCPT to an address containing "reject" -> 550 5.1.1 user unknown
 * - after <limit> messages (0 = none) -> cPanel-style "exceeded the max emails per hour"
 * - AUTH PLAIN/LOGIN with any credentials
 */
$port = (int) $argv[1];
$out = rtrim($argv[2], '/');
$limit = isset($argv[3]) ? (int) $argv[3] : 0;
$count = 0;
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
while ($conn = @stream_socket_accept($server, -1)) {
    $w = function ($s) use ($conn) {
        fwrite($conn, $s . "\r\n");
    };
    $w('220 sink ESMTP');
    $from = '';
    $rcpt = [];
    while (($line = fgets($conn)) !== false) {
        $cmd = rtrim($line, "\r\n");
        $u = strtoupper($cmd);
        if (strpos($u, 'EHLO') === 0) {
            fwrite($conn, "250-sink\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
        } elseif (strpos($u, 'HELO') === 0) {
            $w('250 sink');
        } elseif (strpos($u, 'AUTH PLAIN') === 0) {
            $w('235 ok');
        } elseif (strpos($u, 'AUTH LOGIN') === 0) {
            $w('334 VXNlcm5hbWU6');
            fgets($conn);
            $w('334 UGFzc3dvcmQ6');
            fgets($conn);
            $w('235 ok');
        } elseif (strpos($u, 'MAIL FROM') === 0) {
            $from = $cmd;
            $rcpt = [];
            $w('250 ok');
        } elseif (strpos($u, 'RCPT TO') === 0) {
            if ($limit > 0 && $count >= $limit) {
                $w('550 Domain example.com has exceeded the max emails per hour (' . $limit . '/' . $limit . ' (100%)) allowed.  Message discarded.');
            } elseif (stripos($cmd, 'reject') !== false) {
                $w('550 5.1.1 <' . trim(substr($cmd, 8), ' <>') . '>: Recipient address rejected: User unknown');
            } else {
                $rcpt[] = $cmd;
                $w('250 ok');
            }
        } elseif ($u === 'DATA') {
            $w('354 go');
            $data = '';
            while (($l = fgets($conn)) !== false && rtrim($l, "\r\n") !== '.') {
                $data .= $l;
            }
            $count++;
            file_put_contents($out . '/' . sprintf('%05d', $count) . '.eml', 'X-Envelope: ' . $from . ' ' . implode(' ', $rcpt) . "\r\n" . $data);
            $w('250 queued');
        } elseif ($u === 'RSET') {
            $w('250 ok');
        } elseif ($u === 'QUIT') {
            $w('221 bye');
            break;
        } else {
            $w('502 unknown');
        }
    }
    fclose($conn);
}
