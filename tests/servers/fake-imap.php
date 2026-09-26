<?php
/**
 * Fake IMAP server for the tests: serves the .eml files of a folder as INBOX.
 *   php fake-imap.php <port> <mail dir> <state file>
 * Credentials: user / pa ss"w0rd (quoted string with escaping) or
 * user2 / pässwörd (non-ASCII: sent as a literal).
 * Supports CAPABILITY, LOGIN (quoted or literal), SELECT, UID SEARCH (SINCE /
 * UID a:*), UID FETCH BODY.PEEK[]<0.n>, UID STORE +FLAGS(.SILENT), EXPUNGE,
 * LOGOUT. Flags are written to <state file> as JSON after each change.
 */
list(, $port, $dir, $stateFile) = $argv;
$files = glob(rtrim($dir, '/') . '/*.eml');
sort($files);
$msgs = [];
foreach ($files as $i => $f) {
    $msgs[] = ['uid' => $i + 101, 'data' => str_replace(["\r\n", "\n"], ["\n", "\r\n"], file_get_contents($f)), 'flags' => [], 'name' => basename($f)];
}
$save = function () use (&$msgs, $stateFile) {
    $out = [];
    foreach ($msgs as $m) {
        $out[$m['name']] = $m['flags'];
    }
    file_put_contents($stateFile, json_encode($out));
};
$save();
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
while ($conn = @stream_socket_accept($server, -1)) {
    $w = function ($s) use ($conn) {
        fwrite($conn, $s . "\r\n");
    };
    $w('* OK fake IMAP ready');
    $authed = false;
    while (($line = fgets($conn)) !== false) {
        // Handle synchronising literals: "... {n}" -> "+", then n bytes.
        while (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
            fwrite($conn, "+ go ahead\r\n");
            $lit = '';
            while (strlen($lit) < (int) $m[1]) {
                $lit .= fread($conn, (int) $m[1] - strlen($lit));
            }
            $line = substr($line, 0, -strlen($m[0])) . '"' . addcslashes($lit, '"\\') . '"' . fgets($conn);
        }
        $line = rtrim($line, "\r\n");
        if (!preg_match('/^(\S+) (.*)$/', $line, $m)) {
            continue;
        }
        list(, $tag, $cmd) = $m;
        if (preg_match('/^CAPABILITY/i', $cmd)) {
            $w('* CAPABILITY IMAP4rev1');
            $w($tag . ' OK done');
        } elseif (preg_match('/^LOGIN "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)"$/i', $cmd, $a)) {
            $user = stripcslashes($a[1]);
            $pass = stripcslashes($a[2]);
            $authed = ($user === 'user' && $pass === 'pa ss"w0rd') || ($user === 'user2' && $pass === 'pässwörd');
            $w($tag . ($authed ? ' OK logged in' : ' NO [AUTHENTICATIONFAILED] Invalid credentials'));
        } elseif (!$authed && !preg_match('/^LOGOUT/i', $cmd)) {
            $w($tag . ' NO not authenticated');
        } elseif (preg_match('/^SELECT/i', $cmd)) {
            $max = $msgs ? max(array_column($msgs, 'uid')) : 100;
            $w('* ' . count($msgs) . ' EXISTS');
            $w('* OK [UIDVALIDITY 42] ok');
            $w('* OK [UIDNEXT ' . ($max + 1) . '] ok');
            $w($tag . ' OK [READ-WRITE] selected');
        } elseif (preg_match('/^UID SEARCH (.*)$/i', $cmd, $a)) {
            $uids = array_column($msgs, 'uid');
            if (preg_match('/^UID (\d+):\*$/i', $a[1], $r)) {
                $sel = array_filter($uids, function ($u) use ($r) {
                    return $u >= (int) $r[1];
                });
                if (!$sel && $uids) {
                    $sel = [max($uids)]; // real servers return the last message for n:*
                }
                $uids = $sel;
            }
            $w('* SEARCH ' . implode(' ', $uids));
            $w($tag . ' OK search done');
        } elseif (preg_match('/^UID FETCH (\d+) \(UID BODY\.PEEK\[\]<0\.(\d+)>\)$/i', $cmd, $a)) {
            foreach ($msgs as $seq => $msg) {
                if ($msg['uid'] === (int) $a[1]) {
                    $data = substr($msg['data'], 0, (int) $a[2]);
                    fwrite($conn, '* ' . ($seq + 1) . ' FETCH (UID ' . $msg['uid'] . ' BODY[]<0> {' . strlen($data) . "}\r\n" . $data . ")\r\n");
                }
            }
            $w($tag . ' OK fetch done');
        } elseif (preg_match('/^UID STORE (\d+) \+FLAGS(?:\.SILENT)? \((.*)\)$/i', $cmd, $a)) {
            foreach ($msgs as &$msg) {
                if ($msg['uid'] === (int) $a[1]) {
                    $msg['flags'] = array_values(array_unique(array_merge($msg['flags'], explode(' ', $a[2]))));
                }
            }
            unset($msg);
            $save();
            $w($tag . ' OK store done');
        } elseif (preg_match('/^EXPUNGE/i', $cmd)) {
            $msgs = array_values(array_filter($msgs, function ($m) {
                return !in_array('\\Deleted', $m['flags'], true);
            }));
            $save();
            $w($tag . ' OK expunged');
        } elseif (preg_match('/^LOGOUT/i', $cmd)) {
            $w('* BYE');
            $w($tag . ' OK bye');
            break;
        } else {
            $w($tag . ' BAD unknown command');
        }
    }
    fclose($conn);
}
