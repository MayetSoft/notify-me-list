<?php
/**
 * Builds MIME messages (multipart/alternative, UTF-8) and sends them through
 * the SMTP server configured by the admin. Also holds the transactional
 * e-mails (magic link, confirmation, welcome, test).
 */
final class Mailer
{
    /** @var SmtpClient|null shared connection reused for a batch */
    private static $client = null;

    /** SMTP settings from the database, password decrypted. */
    public static function config(): array
    {
        $password = '';
        try {
            $password = Crypto::decrypt((string) setting('smtp_password', ''));
        } catch (Throwable $e) {
            nm_log('mail', 'Cannot decrypt SMTP password: ' . $e->getMessage());
        }
        $fromEmail = trim((string) setting('from_email', ''));
        if ($fromEmail === '') {
            $fromEmail = (string) setting('admin_email', '');
        }
        $fromName = trim((string) setting('from_name', ''));
        return [
            'host' => trim((string) setting('smtp_host', '')),
            'port' => (int) setting('smtp_port', 587),
            'encryption' => (string) setting('smtp_encryption', 'tls'),
            'username' => (string) setting('smtp_username', ''),
            'password' => $password,
            'verify_cert' => setting('smtp_verify_cert', '1') === '1',
            'timeout' => (int) setting('smtp_timeout', 20),
            'from_email' => $fromEmail,
            'from_name' => $fromName !== '' ? $fromName : nm_site_name(),
            'reply_to' => trim((string) setting('reply_to', '')),
            // Envelope sender (Return-Path): where bounces are delivered. Empty = sender address.
            'return_path' => trim((string) setting('bounce_return_path', '')),
        ];
    }

    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c['host'] !== '' && nm_valid_email($c['from_email']);
    }

    private static function client(): SmtpClient
    {
        if (self::$client === null) {
            self::$client = new SmtpClient(self::config());
        }
        return self::$client;
    }

    public static function closeConnection(): void
    {
        if (self::$client !== null) {
            self::$client->close();
            self::$client = null;
        }
    }

    /**
     * Sends one e-mail to one recipient.
     * $extraHeaders: ['Header-Name' => 'value'].
     */
    public static function send(string $to, string $subject, string $html, string $text, array $extraHeaders = [], ?array $cfg = null, ?SmtpClient $client = null): void
    {
        $cfg = $cfg ?: self::config();
        if (!nm_valid_email($to)) {
            throw new SmtpException(t('mail.invalid_recipient', ['email' => $to]), false, 0);
        }
        if (!nm_valid_email($cfg['from_email'])) {
            throw new SmtpException(t('mail.invalid_from'));
        }
        $data = self::build($cfg, $to, $subject, $html, $text, $extraHeaders);
        $client = $client ?: self::client();
        $wasConnected = $client->isConnected();
        $envelopeFrom = !empty($cfg['return_path']) && nm_valid_email($cfg['return_path']) ? $cfg['return_path'] : $cfg['from_email'];
        try {
            $client->send($envelopeFrom, $to, $data);
        } catch (SmtpException $e) {
            if (!$e->connectionLevel || !$wasConnected) {
                throw $e;
            }
            // The server may have dropped a long-lived connection: retry once on a fresh one.
            $client->close();
            $client->send($envelopeFrom, $to, $data);
        }
        db_exec('INSERT INTO send_log (sent_at) VALUES (?)', [nm_now()]);
    }

    public static function build(array $cfg, string $to, string $subject, string $html, string $text, array $extraHeaders = []): string
    {
        $boundary = '=_nm_' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($cfg['from_email'], '@'), 1) ?: 'localhost';
        $headers = [
            'Date' => date('r'),
            'From' => self::address($cfg['from_email'], $cfg['from_name']),
            'To' => '<' . $to . '>',
            'Subject' => self::encodeHeader(self::clean($subject), 'Subject'),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
        ];
        if ($cfg['reply_to'] !== '' && nm_valid_email($cfg['reply_to'])) {
            $headers['Reply-To'] = '<' . $cfg['reply_to'] . '>';
        }
        foreach ($extraHeaders as $k => $v) {
            $headers[self::clean($k)] = self::clean($v);
        }
        $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';

        $out = '';
        foreach ($headers as $k => $v) {
            $out .= $k . ': ' . $v . "\r\n";
        }
        $out .= "\r\n";
        $out .= "This is a multi-part message in MIME format.\r\n\r\n";
        $out .= '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::qp($text) . "\r\n\r\n";
        $out .= '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::qp($html) . "\r\n\r\n";
        $out .= '--' . $boundary . "--\r\n";
        return $out;
    }

    /** Quoted-printable body with CRLF line breaks (bare LF would be encoded as =0A). */
    private static function qp(string $s): string
    {
        return quoted_printable_encode(preg_replace("/\r\n|\r|\n/", "\r\n", $s));
    }

    /** Removes CR/LF: prevents header injection. */
    private static function clean(string $s): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
    }

    private static function encodeHeader(string $s, string $name): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $s)) {
            return $s;
        }
        return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n", strlen($name) + 2);
    }

    private static function address(string $email, string $name): string
    {
        $name = self::clean($name);
        if ($name === '') {
            return '<' . $email . '>';
        }
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            $name = mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n", 6);
        } else {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }
        return $name . ' <' . $email . '>';
    }

    // --- Layout ----------------------------------------------------------------

    /**
     * Wraps content in the shared e-mail layout. $subscriber (optional) adds
     * the personal unsubscribe footer. Returns [html, text].
     */
    public static function wrap(string $subject, string $contentHtml, string $contentText, ?array $subscriber, string $unsubscribeUrl = ''): array
    {
        if ($subscriber && $unsubscribeUrl === '') {
            $unsubscribeUrl = Tokens::unsubscribeUrl($subscriber);
        }
        $vars = [
            'siteName' => nm_site_name(),
            'siteUrl' => nm_abs_url(''),
            'subject' => $subject,
            'contentHtml' => $contentHtml,
            'contentText' => $contentText,
            'unsubscribeUrl' => $unsubscribeUrl,
            'manageUrl' => nm_abs_url('manage.php'),
            'email' => $subscriber ? $subscriber['email'] : '',
        ];
        return [
            nm_render(NM_ROOT . '/templates/email/layout.html.php', $vars),
            nm_render(NM_ROOT . '/templates/email/layout.txt.php', $vars),
        ];
    }

    /** RFC 8058 one-click unsubscribe headers (Gmail/Yahoo bulk sender rules). */
    public static function unsubscribeHeaders(string $unsubscribeUrl, bool $bulk = true): array
    {
        $h = [
            'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
        if ($bulk) {
            $h['Precedence'] = 'bulk';
        }
        return $h;
    }

    // --- Transactional e-mails -------------------------------------------------

    public static function sendMagicLink(string $email, string $url, string $purpose, string $intro = ''): void
    {
        $minutes = (int) (Tokens::MAGIC_TTL / 60);
        if ($purpose === 'admin') {
            $subject = t('mail.admin_login_subject', ['site' => nm_site_name()]);
            $body = t('mail.admin_login_body', ['minutes' => $minutes]);
            $button = t('mail.admin_login_button');
        } else {
            $subject = t('mail.manage_subject', ['site' => nm_site_name()]);
            $body = ($intro !== '' ? $intro . "\n\n" : '') . t('mail.manage_body', ['minutes' => $minutes]);
            $button = t('mail.manage_button');
        }
        $html = nm_text_to_html($body) . self::buttonHtml($url, $button)
            . '<p style="color:#78716c;font-size:13px">' . e(t('mail.ignore_if_not_you')) . '</p>';
        $text = $body . "\n\n" . $button . ' : ' . $url . "\n\n" . t('mail.ignore_if_not_you');
        list($h, $t) = self::wrap($subject, $html, $text, null);
        self::send($email, $subject, $h, $t, ['Auto-Submitted' => 'auto-generated']);
    }

    public static function sendConfirmation(array $sub, string $confirmUrl): void
    {
        $subject = t('mail.confirm_subject', ['site' => nm_site_name()]);
        $lists = Subscribers::listNames($sub);
        $body = t('mail.confirm_body', ['site' => nm_site_name(), 'days' => (int) (Tokens::CONFIRM_TTL / 86400)]);
        $html = nm_text_to_html($body) . self::listsHtml($lists) . self::buttonHtml($confirmUrl, t('mail.confirm_button'))
            . '<p style="color:#78716c;font-size:13px">' . e(t('mail.confirm_ignore')) . '</p>';
        $text = $body . "\n\n" . self::listsText($lists) . "\n" . t('mail.confirm_button') . ' : ' . $confirmUrl . "\n\n" . t('mail.confirm_ignore');
        $unsub = Tokens::unsubscribeUrl($sub);
        list($h, $t) = self::wrap($subject, $html, $text, $sub, $unsub);
        self::send($sub['email'], $subject, $h, $t, self::unsubscribeHeaders($unsub, false) + ['Auto-Submitted' => 'auto-generated']);
    }

    public static function sendWelcome(array $sub): void
    {
        $subject = t('mail.welcome_subject', ['site' => nm_site_name()]);
        $lists = Subscribers::listNames($sub);
        $body = t('mail.welcome_body', ['site' => nm_site_name()]);
        $html = nm_text_to_html($body) . self::listsHtml($lists)
            . nm_text_to_html(t('mail.welcome_manage', ['url' => nm_abs_url('manage.php')]));
        $text = $body . "\n\n" . self::listsText($lists) . "\n" . t('mail.welcome_manage', ['url' => nm_abs_url('manage.php')]);
        $unsub = Tokens::unsubscribeUrl($sub);
        list($h, $t) = self::wrap($subject, $html, $text, $sub, $unsub);
        self::send($sub['email'], $subject, $h, $t, self::unsubscribeHeaders($unsub, false));
    }

    /**
     * Sends a test e-mail with the given (possibly unsaved) configuration.
     * Returns the SMTP transcript. Throws SmtpException on failure.
     */
    public static function sendTest(array $cfg, string $to, array &$transcript): void
    {
        $client = new SmtpClient($cfg);
        $subject = t('mail.test_subject', ['site' => nm_site_name()]);
        $body = t('mail.test_body', ['date' => nm_format_date(nm_now()), 'host' => $cfg['host']]);
        list($h, $t) = self::wrap($subject, nm_text_to_html($body), $body, null);
        try {
            self::send($to, $subject, $h, $t, [], $cfg, $client);
        } finally {
            $client->close();
            $transcript = $client->transcript;
        }
    }

    // --- Small HTML helpers for e-mails ------------------------------------------

    public static function buttonHtml(string $url, string $label): string
    {
        return '<p style="margin:24px 0"><a href="' . e($url) . '" style="display:inline-block;background:#1c1917;color:#ffffff;'
            . 'text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:600">' . e($label) . '</a></p>'
            . '<p style="font-size:13px;color:#78716c;word-break:break-all">' . e(t('mail.link_fallback')) . '<br>'
            . '<a href="' . e($url) . '" style="color:#57534e">' . e($url) . '</a></p>';
    }

    private static function listsHtml(array $lists): string
    {
        if (!$lists) {
            return '';
        }
        $html = '<p><strong>' . e(t('mail.your_lists')) . '</strong></p><ul>';
        foreach ($lists as $l) {
            $html .= '<li>' . e($l) . '</li>';
        }
        return $html . '</ul>';
    }

    private static function listsText(array $lists): string
    {
        if (!$lists) {
            return '';
        }
        $out = t('mail.your_lists') . "\n";
        foreach ($lists as $l) {
            $out .= ' - ' . $l . "\n";
        }
        return $out;
    }
}
