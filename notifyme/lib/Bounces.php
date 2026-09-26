<?php
/**
 * Bounce handling.
 *
 * Two sources:
 *  - immediate refusals by the SMTP server at send time (Queue::process);
 *  - bounce messages that arrive later in the mailbox that receives errors
 *    (the envelope sender, i.e. the "Return-Path"), read over IMAP by the
 *    feed cron job or the admin "check now" button.
 *
 * Hard bounce (the address does not exist, domain unknown…): the subscriber is
 * deactivated (status "bounced") after bounce_hard_threshold of them (default
 * 1). Soft bounce (mailbox full, policy/spam refusal, temporary trouble):
 * deactivated after bounce_soft_threshold of them within 30 days (default 5).
 * Deactivated subscribers receive nothing, stay visible to the admin who can
 * reactivate or delete them, and can sign up again themselves.
 */
final class BounceParser
{
    /** Diagnostic phrases that mean "this address is dead". */
    const HARD_PATTERN = '/(user unknown|unknown user|no such (user|mailbox|recipient|address)|does not exist|doesn\'t exist|unknown (recipient|address|mailbox)|invalid (recipient|mailbox|address)|mailbox (unavailable|not found|disabled)|recipient address rejected|address rejected|unrouteable|unroutable|account (is )?(disabled|inactive|closed|suspended)|not our customer|no mailbox|user not found|recipient not found|domain (not found|does not exist)|host or domain name not found|name or service not known|nxdomain)/i';

    /**
     * Returns null if $raw is not a bounce, else ['recipients' => [
     *   ['email' => ..., 'kind' => hard|soft, 'status' => '5.1.1', 'reason' => ...], ...]].
     * Delay warnings and out-of-office replies are not bounces.
     */
    public static function parse(string $raw): ?array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $sep = strpos($raw, "\n\n");
        $head = $sep === false ? $raw : substr($raw, 0, $sep);
        $body = $sep === false ? '' : substr($raw, $sep + 2);
        $h = self::headers($head);
        $from = isset($h['from']) ? $h['from'] : '';
        $subject = isset($h['subject']) ? self::decodeHeader($h['subject']) : '';
        $ctype = isset($h['content-type']) ? $h['content-type'] : '';

        $isDsn = stripos($ctype, 'multipart/report') !== false && stripos($ctype, 'delivery-status') !== false;
        $failedHeader = isset($h['x-failed-recipients']) ? $h['x-failed-recipients'] : '';
        $looksLikeBounce = preg_match('/mailer-daemon|postmaster|mail delivery (sub)?system|mail delivery service/i', $from)
            && preg_match('/undeliver|delivery (status|failure|failed|notification|has failed)|returned mail|failure notice|mail delivery failed|non.?delivery|could not be delivered|not delivered|rejected|returned to sender|delivery problem/i', $subject);
        if (!$isDsn && $failedHeader === '' && !$looksLikeBounce) {
            return null;
        }
        // Delay warnings ("still trying") are not failures.
        if (preg_match('/^(warning|delayed|delivery delayed)|delivery status notification \(delay\)|message delayed/i', $subject)) {
            return null;
        }

        $decodedBody = self::decodeParts($body);
        $recipients = [];

        // 1. Standard DSN (RFC 3464): per-recipient field groups.
        if ($isDsn || stripos($decodedBody, 'Final-Recipient:') !== false) {
            foreach (preg_split('/\n\s*\n/', $decodedBody) as $group) {
                if (!preg_match('/^Final-Recipient:\s*(?:rfc822|RFC822)?\s*;?\s*<?([^\s<>;]+@[^\s<>;]+)>?/mi', $group, $m)) {
                    continue;
                }
                $email = self::cleanEmail($m[1]);
                $action = preg_match('/^Action:\s*(\S+)/mi', $group, $a) ? strtolower($a[1]) : 'failed';
                if ($action !== 'failed') {
                    continue; // delayed, delivered, relayed, expanded
                }
                $status = preg_match('/^Status:\s*([245]\.\d{1,3}\.\d{1,3})/mi', $group, $s) ? $s[1] : '';
                $diag = preg_match('/^Diagnostic-Code:\s*(?:smtp;\s*)?(.+(?:\n[ \t].+)*)/mi', $group, $d) ? trim(preg_replace('/\s+/', ' ', $d[1])) : '';
                if ($email !== '') {
                    $recipients[$email] = ['email' => $email, 'kind' => self::classify($status, $diag), 'status' => $status, 'reason' => mb_substr($diag !== '' ? $diag : $status, 0, 300)];
                }
            }
        }

        // 2. Exim / cPanel: X-Failed-Recipients header.
        if (!$recipients && $failedHeader !== '') {
            $reason = self::findReason($decodedBody);
            $status = preg_match('/\b([45]\.\d{1,3}\.\d{1,3})\b/', $reason, $s) ? $s[1] : '';
            $kind = self::classify($status, $reason);
            if ($status === '' && preg_match('/permanent error|permanently|550|551|553|554/i', $decodedBody) && !preg_match('/temporar/i', $reason)) {
                $kind = preg_match(self::HARD_PATTERN, $decodedBody) ? 'hard' : $kind;
            }
            foreach (preg_split('/[,\s]+/', $failedHeader) as $addr) {
                $email = self::cleanEmail($addr);
                if ($email !== '') {
                    $recipients[$email] = ['email' => $email, 'kind' => $kind, 'status' => $status, 'reason' => mb_substr($reason, 0, 300)];
                }
            }
        }

        // 3. Anything else that looks like a bounce: candidate addresses from the
        //    text; the caller keeps only known subscribers.
        if (!$recipients) {
            $reason = self::findReason($decodedBody);
            $status = preg_match('/\b([45]\.\d{1,3}\.\d{1,3})\b/', $decodedBody, $s) ? $s[1] : '';
            $kind = self::classify($status, $reason !== '' ? $reason : $decodedBody);
            if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $decodedBody, $all)) {
                foreach (array_unique($all[0]) as $addr) {
                    $email = self::cleanEmail($addr);
                    if ($email !== '') {
                        $recipients[$email] = ['email' => $email, 'kind' => $kind, 'status' => $status, 'reason' => mb_substr($reason, 0, 300), 'guessed' => true];
                    }
                }
            }
        }
        return ['recipients' => array_values($recipients)];
    }

    /** hard | soft, from an enhanced status code and the diagnostic text. */
    public static function classify(string $status, string $text): string
    {
        if ($status !== '' && $status[0] === '4') {
            return 'soft';
        }
        if (preg_match('/^5\.(1|4)\./', $status) || $status === '5.2.1') {
            return 'hard';
        }
        if ($status === '5.2.2' || preg_match('/(mailbox|quota) (is )?full|over quota|quota exceeded|insufficient storage/i', $text)) {
            return 'soft';
        }
        return preg_match(self::HARD_PATTERN, $text) ? 'hard' : 'soft';
    }

    private static function headers(string $head): array
    {
        $head = preg_replace("/\n[ \t]+/", ' ', $head); // unfold
        $out = [];
        foreach (explode("\n", $head) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = strtolower(trim(substr($line, 0, $pos)));
                if (!isset($out[$name])) {
                    $out[$name] = trim(substr($line, $pos + 1));
                }
            }
        }
        return $out;
    }

    private static function decodeHeader(string $s): string
    {
        if (strpos($s, '=?') !== false && function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($s);
        }
        return $s;
    }

    /**
     * Flattens a MIME body into readable text: decodes base64 and
     * quoted-printable parts (bounces from Gmail/Outlook are often encoded).
     */
    private static function decodeParts(string $body): string
    {
        $out = $body;
        // Decode every part that declares a transfer encoding.
        if (preg_match_all('/Content-Transfer-Encoding:\s*(base64|quoted-printable)[^\n]*\n(?:[^\n]+\n)*\n(.*?)(?=\n--|\z)/is', $body, $parts, PREG_SET_ORDER)) {
            foreach ($parts as $p) {
                $decoded = strtolower($p[1]) === 'base64' ? base64_decode(preg_replace('/\s+/', '', $p[2]), true) : quoted_printable_decode($p[2]);
                if ($decoded !== false && $decoded !== '') {
                    $out .= "\n\n" . str_replace("\r\n", "\n", $decoded);
                }
            }
        }
        return $out;
    }

    /** The most informative error line of a bounce text. */
    private static function findReason(string $text): string
    {
        foreach (['/^\s*(?:SMTP error from remote mail server after [^:]+:\s*)?((?:host [^:]+:\s*)?[45]\d\d[ -].+)$/mi', '/^\s*(.*(?:' . substr(self::HARD_PATTERN, 2, -3) . ').*)$/mi', '/^\s*(.*(?:mailbox (is )?full|quota).*)$/mi'] as $re) {
            if (preg_match($re, $text, $m)) {
                return trim(preg_replace('/\s+/', ' ', $m[1]));
            }
        }
        return '';
    }

    private static function cleanEmail(string $s): string
    {
        $s = strtolower(trim($s, " \t<>\"'();,."));
        return nm_valid_email($s) ? $s : '';
    }
}

final class Bounces
{
    const SOFT_WINDOW = 2592000; // 30 days

    public static function enabled(): bool
    {
        return setting('bounce_enabled', '0') === '1';
    }

    /**
     * Records a bounce for $email (ignored if it is not a subscriber) and
     * deactivates the subscriber when the threshold is reached.
     * Returns true if the subscriber was deactivated by this bounce.
     */
    public static function register(string $email, string $kind, string $status, string $reason, string $source): bool
    {
        $sub = Subscribers::findByEmail($email);
        if (!$sub) {
            return false;
        }
        $now = nm_now();
        $kind = $kind === 'hard' ? 'hard' : 'soft';
        db_exec(
            'INSERT INTO bounce_log (email, subscriber_id, kind, status_code, reason, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$sub['email'], $sub['id'], $kind, mb_substr($status, 0, 20), mb_substr($reason, 0, 500), $source, $now]
        );
        $hard = (int) $sub['bounce_hard'];
        $soft = (int) $sub['bounce_soft'];
        if ($kind === 'hard') {
            $hard++;
        } else {
            // Soft bounces only count within a 30-day window.
            $soft = ($sub['last_bounce_at'] && (int) $sub['last_bounce_at'] > $now - self::SOFT_WINDOW) ? $soft + 1 : 1;
        }
        $deactivate = $sub['status'] === 'active' && (
            $hard >= setting_int('bounce_hard_threshold', 1, 100) || $soft >= setting_int('bounce_soft_threshold', 1, 100)
        );
        db_exec(
            'UPDATE subscribers SET bounce_hard = ?, bounce_soft = ?, last_bounce_at = ?, last_bounce_reason = ?, updated_at = ?' . ($deactivate ? ", status = 'bounced'" : '') . ' WHERE id = ?',
            [$hard, $soft, $now, mb_substr(($status !== '' ? $status . ' ' : '') . $reason, 0, 500), $now, $sub['id']]
        );
        if ($deactivate) {
            db_exec("UPDATE queue SET status = 'skipped', error = 'bounced', updated_at = ? WHERE subscriber_id = ? AND status = 'pending'", [$now, $sub['id']]);
            nm_log('bounces', 'Subscriber #' . $sub['id'] . ' deactivated after ' . $kind . ' bounce: ' . $reason);
        }
        return $deactivate;
    }

    /** Admin: puts a bounced subscriber back on the lists. */
    public static function reactivate(int $id): void
    {
        db_exec(
            "UPDATE subscribers SET status = 'active', bounce_hard = 0, bounce_soft = 0, last_bounce_reason = '', updated_at = ? WHERE id = ? AND status = 'bounced'",
            [nm_now(), $id]
        );
        nm_log('bounces', 'Subscriber #' . $id . ' reactivated by the admin');
    }

    /** IMAP settings (possibly the SMTP ones), password decrypted. */
    public static function imapConfig(): array
    {
        $same = setting('bounce_same_as_smtp', '1') === '1';
        $password = '';
        try {
            $password = Crypto::decrypt((string) setting($same ? 'smtp_password' : 'imap_password', ''));
        } catch (Throwable $e) {
            nm_log('bounces', 'Cannot decrypt IMAP password: ' . $e->getMessage());
        }
        return [
            'host' => trim((string) setting($same ? 'smtp_host' : 'imap_host', '')),
            'port' => (int) setting('imap_port', 993),
            'encryption' => (string) setting('imap_encryption', 'ssl'),
            'username' => (string) setting($same ? 'smtp_username' : 'imap_username', ''),
            'password' => $password,
            'verify_cert' => setting('smtp_verify_cert', '1') === '1',
            'timeout' => 30,
            'folder' => (string) setting('imap_folder', 'INBOX'),
        ];
    }

    /** Connects and opens the folder: used by the admin "test" button. Returns folder info. */
    public static function testConnection(array $cfg, array &$transcript): array
    {
        $imap = new ImapClient($cfg);
        try {
            $imap->connect();
            $info = $imap->select($cfg['folder'] !== '' ? $cfg['folder'] : 'INBOX');
            $imap->logout();
            return $info;
        } finally {
            $transcript = $imap->transcript;
        }
    }

    /**
     * Reads the new messages of the bounce mailbox and processes the bounces.
     * Messages that are not bounces are left untouched (still unread).
     * Returns ['checked' => n, 'bounces' => n, 'deactivated' => n].
     */
    public static function processMailbox(int $maxMessages = 100): array
    {
        $report = ['checked' => 0, 'bounces' => 0, 'deactivated' => 0];
        $lock = nm_lock('bounces');
        if (!$lock) {
            return $report;
        }
        $cfg = self::imapConfig();
        $imap = new ImapClient($cfg);
        try {
            $imap->connect();
            $info = $imap->select($cfg['folder'] !== '' ? $cfg['folder'] : 'INBOX');
            $lastUid = (int) setting('bounce_last_uid', 0);
            if ((int) setting('bounce_uidvalidity', 0) !== $info['uidvalidity']) {
                // First run, or the server renumbered the folder: only look at the last 7 days.
                $lastUid = 0;
                setting_set('bounce_uidvalidity', (string) $info['uidvalidity']);
            }
            if ($info['exists'] === 0) {
                $uids = [];
            } elseif ($lastUid === 0) {
                $uids = $imap->uidSearch('SINCE ' . date('j-M-Y', nm_now() - 7 * 86400));
            } else {
                $uids = array_filter($imap->uidSearch('UID ' . ($lastUid + 1) . ':*'), function ($u) use ($lastUid) {
                    return $u > $lastUid;
                });
            }
            $action = (string) setting('bounce_action', 'seen');
            $deleted = false;
            foreach (array_slice(array_values($uids), 0, $maxMessages) as $uid) {
                $report['checked']++;
                $raw = $imap->fetchRaw($uid);
                $parsed = $raw !== null ? BounceParser::parse($raw) : null;
                if ($parsed) {
                    $own = array_map('strtolower', array_filter([(string) setting('from_email'), (string) setting('admin_email'), (string) setting('reply_to'), (string) setting('bounce_return_path'), $cfg['username']]));
                    $known = array_filter($parsed['recipients'], function ($r) use ($own) {
                        return !(!empty($r['guessed']) && in_array($r['email'], $own, true)) && Subscribers::findByEmail($r['email']) !== null;
                    });
                    // A guessed bounce naming several subscribers is ambiguous: ignore it.
                    $guessed = $known && !empty(reset($known)['guessed']);
                    if ($known && !($guessed && count($known) > 1)) {
                        $report['bounces']++;
                        foreach ($known as $r) {
                            if (self::register($r['email'], $r['kind'], $r['status'], $r['reason'], 'imap')) {
                                $report['deactivated']++;
                            }
                        }
                        if ($action === 'delete') {
                            $imap->addFlags($uid, '\\Seen \\Deleted');
                            $deleted = true;
                        } elseif ($action === 'seen') {
                            $imap->addFlags($uid, '\\Seen');
                        }
                    }
                }
                setting_set('bounce_last_uid', (string) $uid);
            }
            if ($lastUid === 0 && !$uids && $info['uidnext'] > 0) {
                setting_set('bounce_last_uid', (string) ($info['uidnext'] - 1));
            }
            if ($deleted) {
                $imap->expunge();
            }
            $imap->logout();
            setting_set('bounce_last_error', '');
        } catch (Throwable $e) {
            setting_set('bounce_last_error', mb_substr($e->getMessage(), 0, 500));
            nm_log('bounces', 'Mailbox check failed: ' . $e->getMessage());
            throw $e;
        } finally {
            setting_set('bounce_last_run', (string) nm_now());
            nm_unlock($lock);
        }
        if ($report['bounces'] > 0) {
            nm_log('bounces', sprintf('Mailbox: %d checked, %d bounces, %d deactivated', $report['checked'], $report['bounces'], $report['deactivated']));
        }
        return $report;
    }
}
