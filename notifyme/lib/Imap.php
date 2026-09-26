<?php
/**
 * Minimal, dependency-free IMAP4rev1 client (RFC 3501), just enough to read
 * bounce messages: LOGIN, SELECT, UID SEARCH, UID FETCH (BODY.PEEK, so the
 * unread state of other messages is never changed), UID STORE, EXPUNGE.
 * Written in PHP because the imap extension is missing on many hosts and was
 * removed from core PHP in 8.4.
 */
class ImapException extends RuntimeException
{
}

class ImapClient
{
    private $host;
    private $port;
    private $encryption;
    private $username;
    private $password;
    private $timeout;
    private $verifyCert;
    private $sock = null;
    private $tagCounter = 0;
    /** Transcript (password redacted), shown by the admin "test" button. */
    public $transcript = [];

    public function __construct(array $cfg)
    {
        $this->host = (string) $cfg['host'];
        $this->port = (int) $cfg['port'];
        $this->encryption = (string) $cfg['encryption'];
        $this->username = (string) $cfg['username'];
        $this->password = (string) $cfg['password'];
        $this->timeout = max(5, (int) (isset($cfg['timeout']) ? $cfg['timeout'] : 30));
        $this->verifyCert = !empty($cfg['verify_cert']);
    }

    public function connect(): void
    {
        if ($this->host === '' || $this->port <= 0) {
            throw new ImapException(t('imap.not_configured'));
        }
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifyCert,
            'verify_peer_name' => $this->verifyCert,
            'allow_self_signed' => !$this->verifyCert,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ]]);
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new ImapException(t('imap.connect_failed', ['host' => $this->host, 'port' => $this->port, 'error' => trim($errstr) ?: ('errno ' . $errno)]));
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $greeting = $this->readLine();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            throw new ImapException(t('imap.unexpected', ['reply' => $greeting]));
        }
        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($this->sock, true, $method)) {
                $err = error_get_last();
                throw new ImapException(t('smtp.tls_failed', ['error' => $err ? $err['message'] : '']));
            }
        }
        $this->command(['LOGIN ', self::astring($this->username), ' ', self::astring($this->password)], 'LOGIN ' . $this->username . ' ***');
    }

    /** Opens a folder. Returns ['exists' => n, 'uidvalidity' => n, 'uidnext' => n]. */
    public function select(string $folder): array
    {
        $out = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($this->command(['SELECT ', self::astring($folder)]) as $resp) {
            if (preg_match('/^\* (\d+) EXISTS/i', $resp['text'], $m)) {
                $out['exists'] = (int) $m[1];
            } elseif (preg_match('/UIDVALIDITY (\d+)/i', $resp['text'], $m)) {
                $out['uidvalidity'] = (int) $m[1];
            } elseif (preg_match('/UIDNEXT (\d+)/i', $resp['text'], $m)) {
                $out['uidnext'] = (int) $m[1];
            }
        }
        return $out;
    }

    /** UID SEARCH with a raw criteria string (built by the caller from safe values). Returns UIDs. */
    public function uidSearch(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria) as $resp) {
            if (preg_match('/^\* SEARCH\s*(.*)$/i', $resp['text'], $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) {
                    if (ctype_digit($u)) {
                        $uids[] = (int) $u;
                    }
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /** First $maxBytes of a message, without marking it as read. Null if it vanished. */
    public function fetchRaw(int $uid, int $maxBytes = 262144): ?string
    {
        foreach ($this->command('UID FETCH ' . $uid . ' (UID BODY.PEEK[]<0.' . $maxBytes . '>)') as $resp) {
            if (preg_match('/FETCH \(.*UID ' . $uid . '\b/i', $resp['text']) && $resp['literals']) {
                return $resp['literals'][0];
            }
        }
        return null;
    }

    public function addFlags(int $uid, string $flags): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (' . $flags . ')');
    }

    public function expunge(): void
    {
        $this->command('EXPUNGE');
    }

    public function logout(): void
    {
        if (is_resource($this->sock)) {
            try {
                $this->command('LOGOUT');
            } catch (Throwable $e) {
                // ignore
            }
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    public function __destruct()
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
    }

    // --- protocol ----------------------------------------------------------------

    /** Quoted string, or a literal marker for values that cannot be quoted. */
    private static function astring(string $s): array
    {
        if (preg_match('/[\r\n\x00\x80-\xFF]/', $s)) {
            return ['literal' => $s];
        }
        return ['quoted' => '"' . addcslashes($s, '"\\') . '"'];
    }

    /**
     * Sends a tagged command and returns its untagged responses:
     * [['text' => line with literals replaced by {n}, 'literals' => [...]], ...].
     * $parts: a string, or a list of strings / astring() arrays.
     */
    private function command($parts, ?string $logAs = null): array
    {
        if (!is_resource($this->sock)) {
            throw new ImapException(t('imap.not_connected'));
        }
        $tag = 'N' . (++$this->tagCounter);
        $parts = is_array($parts) ? $parts : [$parts];
        $this->transcript[] = 'C: ' . $tag . ' ' . ($logAs !== null ? $logAs : implode('', array_map(function ($p) {
            return is_array($p) ? (isset($p['quoted']) ? $p['quoted'] : '{literal}') : $p;
        }, $parts)));
        $buffer = $tag . ' ';
        foreach ($parts as $p) {
            if (is_string($p)) {
                $buffer .= $p;
            } elseif (isset($p['quoted'])) {
                $buffer .= $p['quoted'];
            } else {
                // Synchronising literal: announce the size, wait for "+", then send.
                $this->write($buffer . '{' . strlen($p['literal']) . "}\r\n");
                $cont = $this->readLine();
                if (strpos($cont, '+') !== 0) {
                    throw new ImapException(t('imap.unexpected', ['reply' => $cont]));
                }
                $buffer = $p['literal'];
            }
        }
        $this->write($buffer . "\r\n");

        $responses = [];
        while (true) {
            $resp = $this->readResponse();
            if (strpos($resp['text'], $tag . ' ') === 0) {
                if (!preg_match('/^' . $tag . ' OK/i', $resp['text'])) {
                    throw new ImapException(t('imap.command_failed', ['reply' => substr($resp['text'], strlen($tag) + 1)]));
                }
                return $responses;
            }
            $responses[] = $resp;
        }
    }

    /** Reads one logical response, including any {n} literals it announces. */
    private function readResponse(): array
    {
        $text = '';
        $literals = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $text .= $line;
                $literals[] = $this->readBytes((int) $m[1]);
                continue; // the response goes on after the literal
            }
            $text .= $line;
            return ['text' => $text, 'literals' => $literals];
        }
    }

    private function readLine(): string
    {
        $line = @fgets($this->sock, 65536);
        if ($line === false) {
            $meta = is_resource($this->sock) ? stream_get_meta_data($this->sock) : ['timed_out' => false];
            throw new ImapException(!empty($meta['timed_out']) ? t('imap.timeout') : t('imap.connection_closed'));
        }
        $line = rtrim($line, "\r\n");
        $this->transcript[] = 'S: ' . (strlen($line) > 300 ? substr($line, 0, 300) . '…' : $line);
        if (count($this->transcript) > 400) {
            array_splice($this->transcript, 0, 100);
        }
        return $line;
    }

    private function readBytes(int $n): string
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = @fread($this->sock, min(65536, $n - strlen($data)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out']) || feof($this->sock)) {
                    throw new ImapException(t('imap.connection_closed'));
                }
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->sock, substr($data, $written, 8192));
            if ($n === false || $n === 0) {
                throw new ImapException(t('imap.connection_closed'));
            }
            $written += $n;
        }
    }
}
