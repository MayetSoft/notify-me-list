<?php
/**
 * One token mechanism for everything that arrives by e-mail:
 *  - admin magic-link login        (purpose "admin",   15 min, single use)
 *  - subscriber self-service login (purpose "manage",  15 min, single use)
 *  - double opt-in confirmation    (purpose "confirm", 7 days, single use)
 * Tokens are 256-bit random values; only a keyed hash is stored in the DB.
 *
 * Unsubscribe links are different on purpose: they must keep working in
 * every old e-mail, so they are stateless HMAC signatures of the subscriber
 * id + e-mail (they become invalid once the subscriber is deleted).
 */
final class Tokens
{
    const MAGIC_TTL = 900;           // 15 minutes
    const CONFIRM_TTL = 604800;      // 7 days
    const MAGIC_MAX_PER_EMAIL = 3;   // per 15 minutes
    const MAGIC_MAX_PER_IP = 10;     // per 15 minutes
    const RATE_WINDOW = 900;

    public static function create(string $purpose, string $email, ?int $subscriberId, int $ttl): string
    {
        $raw = nm_b64url_encode(random_bytes(32));
        $now = nm_now();
        db_exec(
            'INSERT INTO tokens (token_hash, purpose, email, subscriber_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
            [Crypto::tokenHash($raw), $purpose, nm_normalize_email($email), $subscriberId, $now, $now + $ttl]
        );
        return $raw;
    }

    /** Returns the token row if it is valid (unused, not expired), without using it. */
    public static function find(string $raw, string $purpose): ?array
    {
        if ($raw === '' || strlen($raw) > 100) {
            return null;
        }
        return db_one(
            'SELECT * FROM tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            [Crypto::tokenHash($raw), $purpose, nm_now()]
        );
    }

    /** Atomically marks the token as used. Returns its row, or null if invalid/already used. */
    public static function consume(string $raw, string $purpose): ?array
    {
        $row = self::find($raw, $purpose);
        if (!$row) {
            return null;
        }
        $n = db_exec('UPDATE tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [nm_now(), $row['id']]);
        return $n === 1 ? $row : null;
    }

    /** Invalidates every unused token of a purpose for an e-mail (e.g. after login). */
    public static function revokeAll(string $purpose, string $email): void
    {
        db_exec('UPDATE tokens SET used_at = ? WHERE purpose = ? AND email = ? AND used_at IS NULL', [nm_now(), $purpose, nm_normalize_email($email)]);
    }

    /**
     * Per-e-mail + per-IP rate limiting for anything that sends an e-mail to
     * an address typed by a visitor. Prevents inbox spam-bombing.
     */
    public static function allowMailTo(string $email): bool
    {
        $email = nm_normalize_email($email);
        if (!nm_rate_limit('mail:' . $email, self::MAGIC_MAX_PER_EMAIL, self::RATE_WINDOW)) {
            nm_log('security', 'Rate limit reached for e-mail ' . $email);
            return false;
        }
        $ip = nm_client_ip();
        if ($ip !== '' && !nm_rate_limit('mailip:' . $ip, self::MAGIC_MAX_PER_IP, self::RATE_WINDOW)) {
            nm_log('security', 'Rate limit reached for IP ' . $ip);
            return false;
        }
        return true;
    }

    /**
     * Creates and e-mails a magic link. $purpose: "admin" or "manage".
     * Returns true if an e-mail was sent. Callers must show the same neutral
     * message whatever the result, so nobody can probe which addresses exist.
     */
    public static function sendMagicLink(string $purpose, string $email, ?int $subscriberId = null, string $intro = ''): bool
    {
        if (!self::allowMailTo($email)) {
            return false;
        }
        $raw = self::create($purpose, $email, $subscriberId, self::MAGIC_TTL);
        $url = $purpose === 'admin'
            ? nm_abs_url('admin/login.php', ['token' => $raw])
            : nm_abs_url('manage.php', ['token' => $raw]);
        try {
            Mailer::sendMagicLink($email, $url, $purpose, $intro);
            return true;
        } catch (Throwable $e) {
            nm_log('mail', 'Magic link to ' . $email . ' failed: ' . $e->getMessage());
            return false;
        }
    }

    // --- Unsubscribe (stateless) --------------------------------------------

    public static function unsubscribeToken(array $subscriber): string
    {
        $id = (int) $subscriber['id'];
        return $id . '-' . Crypto::sign('unsub|' . $id . '|' . nm_normalize_email($subscriber['email']) . '|' . $subscriber['created_at']);
    }

    public static function unsubscribeUrl(array $subscriber): string
    {
        return nm_abs_url('unsubscribe.php', ['t' => self::unsubscribeToken($subscriber)]);
    }

    /** Returns the subscriber matching an unsubscribe token, or null. */
    public static function subscriberFromUnsubscribeToken(string $token): ?array
    {
        if (!preg_match('/^(\d{1,12})-([A-Za-z0-9_-]{10,64})$/', $token, $m)) {
            return null;
        }
        $sub = Subscribers::find((int) $m[1]);
        if (!$sub) {
            return null;
        }
        return hash_equals(self::unsubscribeToken($sub), $token) ? $sub : null;
    }

    // --- Anti-bot form stamp --------------------------------------------------

    /** Signed timestamp embedded in the public form (bots submit instantly). */
    public static function formStamp(): string
    {
        $ts = (string) nm_now();
        return $ts . '.' . Crypto::sign('form|' . $ts, 8);
    }

    public static function checkFormStamp(string $stamp, int $minSeconds = 2, int $maxSeconds = 86400): bool
    {
        if (!preg_match('/^(\d+)\.([A-Za-z0-9_-]+)$/', $stamp, $m) || !Crypto::verify('form|' . $m[1], $m[2], 8)) {
            return false;
        }
        $age = nm_now() - (int) $m[1];
        return $age >= $minSeconds && $age <= $maxSeconds;
    }
}
