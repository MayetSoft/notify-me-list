<?php
/**
 * Minimal, dependency-free SMTP client (RFC 5321) with STARTTLS / implicit
 * TLS and AUTH PLAIN / LOGIN. One connection is reused for a whole batch.
 */

class SmtpException extends RuntimeException
{
    /** true when the connection/authentication failed (stop the batch), false for a per-recipient refusal. */
    public $connectionLevel;
    /** SMTP reply code (0 if none). */
    public $smtpCode;

    public function __construct(string $message, bool $connectionLevel = true, int $smtpCode = 0)
    {
        parent::__construct($message);
        $this->connectionLevel = $connectionLevel;
        $this->smtpCode = $smtpCode;
    }

    /** 4xx replies are temporary and worth retrying later. */
    public function isTemporary(): bool
    {
        return $this->connectionLevel || ($this->smtpCode >= 400 && $this->smtpCode < 500);
    }
}

class SmtpClient
{
    private $host;
    private $port;
    private $encryption;
    private $username;
    private $password;
    private $timeout;
    private $verifyCert;
    private $sock = null;
    private $capabilities = [];
    /** Transcript of the conversation (password redacted), useful for the test page. */
    public $transcript = [];

    public function __construct(array $cfg)
    {
        $this->host = (string) $cfg['host'];
        $this->port = (int) $cfg['port'];
        $this->encryption = (string) $cfg['encryption'];
        $this->username = (string) $cfg['username'];
        $this->password = (string) $cfg['password'];
        $this->timeout = max(5, (int) $cfg['timeout']);
        $this->verifyCert = !empty($cfg['verify_cert']);
    }

    public function isConnected(): bool
    {
        return is_resource($this->sock) && !feof($this->sock);
    }

    public function connect(): void
    {
        if ($this->host === '' || $this->port <= 0) {
            throw new SmtpException(t('smtp.not_configured'));
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
            throw new SmtpException(t('smtp.connect_failed', ['host' => $this->host, 'port' => $this->port, 'error' => trim($errstr) ?: ('errno ' . $errno)]));
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $this->expect([220], null);
        $this->ehlo();

        if ($this->encryption === 'tls') {
            if (!isset($this->capabilities['STARTTLS'])) {
                throw new SmtpException(t('smtp.no_starttls'));
            }
            $this->command('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($this->sock, true, $method)) {
                $err = error_get_last();
                throw new SmtpException(t('smtp.tls_failed', ['error' => $err ? $err['message'] : '']));
            }
            $this->ehlo();
        }

        if ($this->username !== '') {
            $this->authenticate();
        }
    }

    private function ehlo(): void
    {
        $name = 'localhost';
        if (!empty($_SERVER['SERVER_NAME']) && preg_match('/^[a-z0-9.-]+$/i', (string) $_SERVER['SERVER_NAME'])) {
            $name = (string) $_SERVER['SERVER_NAME'];
        } elseif (function_exists('gethostname') && preg_match('/^[a-z0-9.-]+$/i', (string) gethostname())) {
            $name = (string) gethostname();
        }
        try {
            $lines = $this->command('EHLO ' . $name, [250]);
        } catch (SmtpException $e) {
            $lines = $this->command('HELO ' . $name, [250]);
        }
        $this->capabilities = [];
        foreach (array_slice($lines, 1) as $l) {
            $parts = explode(' ', strtoupper(trim(substr($l, 4))), 2);
            $this->capabilities[$parts[0]] = isset($parts[1]) ? $parts[1] : '';
        }
    }

    private function authenticate(): void
    {
        $auth = isset($this->capabilities['AUTH']) ? ' ' . $this->capabilities['AUTH'] . ' ' : ' PLAIN LOGIN ';
        if (strpos($auth, ' PLAIN ') !== false) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235], 'AUTH PLAIN ***');
        } elseif (strpos($auth, ' LOGIN ') !== false) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334], '*** (user)');
            $this->command(base64_encode($this->password), [235], '*** (password)');
        } else {
            throw new SmtpException(t('smtp.no_auth_method', ['methods' => trim($auth)]));
        }
    }

    /**
     * Sends one message. $data is the full RFC 5322 message (headers + body).
     * Throws SmtpException; connectionLevel=false means only this recipient failed.
     */
    public function send(string $from, string $to, string $data): void
    {
        if (!$this->isConnected()) {
            $this->connect();
        }
        try {
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
        } catch (SmtpException $e) {
            if (!$e->connectionLevel) {
                // Envelope refused: reset the transaction and keep the connection.
                try {
                    $this->command('RSET', [250]);
                } catch (SmtpException $ignored) {
                    $this->close();
                }
            }
            throw $e;
        }
        // Normalise line endings and dot-stuff (RFC 5321 §4.5.2).
        $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data);
        $this->write($data . "\r\n.\r\n", '[message body]');
        $this->expect([250], 'DATA');
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            try {
                $this->write("QUIT\r\n", 'QUIT');
                $this->read();
            } catch (Throwable $e) {
                // ignore
            }
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    // --- low level -----------------------------------------------------------

    private function command(string $line, array $okCodes, ?string $logAs = null): array
    {
        $this->write($line . "\r\n", $logAs === null ? $line : $logAs);
        return $this->expect($okCodes, $logAs === null ? $line : $logAs);
    }

    private function write(string $data, string $logAs): void
    {
        $this->transcript[] = 'C: ' . $logAs;
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->sock, substr($data, $written, 8192));
            if ($n === false || $n === 0) {
                $this->sock = null;
                throw new SmtpException(t('smtp.write_failed'));
            }
            $written += $n;
        }
    }

    /** Reads a (possibly multi-line) reply. Returns its lines. */
    private function read(): array
    {
        $lines = [];
        while (true) {
            $line = @fgets($this->sock, 4096);
            if ($line === false) {
                $meta = is_resource($this->sock) ? stream_get_meta_data($this->sock) : ['timed_out' => false];
                $this->sock = null;
                throw new SmtpException(!empty($meta['timed_out']) ? t('smtp.timeout') : t('smtp.connection_closed'));
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            $this->transcript[] = 'S: ' . $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $lines;
    }

    private function expect(array $okCodes, ?string $after): array
    {
        $lines = $this->read();
        $code = (int) substr($lines[0], 0, 3);
        if (!in_array($code, $okCodes, true)) {
            $msg = implode(' ', $lines);
            // Rejections of MAIL/RCPT/DATA content are per-message; everything else breaks the session.
            $perMessage = $after !== null && preg_match('/^(MAIL FROM|RCPT TO|DATA)/', $after) && $code >= 400 && $code !== 421;
            if (!$perMessage) {
                $this->close();
            }
            $hint = '';
            if ($code === 535 || $code === 534) {
                $hint = ' — ' . t('smtp.auth_failed_hint');
            }
            throw new SmtpException(t('smtp.unexpected_reply', ['command' => (string) $after, 'reply' => $msg]) . $hint, !$perMessage, $code);
        }
        return $lines;
    }
}
