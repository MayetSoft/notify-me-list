<?php
/**
 * SQLite access and schema. Only SQL features available in old SQLite
 * versions (3.7+) are used, because some shared hosts ship an old library.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!extension_loaded('pdo_sqlite')) {
            nm_fail(t('err.requirements_title'), t('req.pdo_sqlite_missing'));
        }
        $pdo = new PDO('sqlite:' . NM_DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 15,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 15000');
    }
    return $pdo;
}

/** Runs $fn inside a transaction and returns its result. */
function db_tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_value(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** "?, ?, ?" for IN () clauses. */
function db_placeholders(array $values): string
{
    return implode(', ', array_fill(0, max(1, count($values)), '?'));
}

const NM_SCHEMA_VERSION = 2;

/**
 * Schema changes after version 1, applied in order by db_migrate().
 * Never edit a released migration: add a new one.
 */
function db_migrations(): array
{
    return [
        // 1.1.0: bounce handling + scheduled campaigns.
        2 => [
            "ALTER TABLE subscribers ADD COLUMN bounce_hard INTEGER NOT NULL DEFAULT 0",
            "ALTER TABLE subscribers ADD COLUMN bounce_soft INTEGER NOT NULL DEFAULT 0",
            "ALTER TABLE subscribers ADD COLUMN last_bounce_at INTEGER",
            "ALTER TABLE subscribers ADD COLUMN last_bounce_reason TEXT NOT NULL DEFAULT ''",
            "CREATE TABLE IF NOT EXISTS bounce_log (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                email         TEXT NOT NULL,
                subscriber_id INTEGER,
                kind          TEXT NOT NULL,          -- hard | soft
                status_code   TEXT NOT NULL DEFAULT '',
                reason        TEXT NOT NULL DEFAULT '',
                source        TEXT NOT NULL,          -- imap | smtp
                created_at    INTEGER NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_bounce_log_created ON bounce_log(created_at)",
            "ALTER TABLE campaigns ADD COLUMN scheduled_at INTEGER",
        ],
    ];
}

/** Current schema version stored in the database (0 if none). */
function db_schema_version(): int
{
    try {
        $v = db()->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetchColumn();
        return $v === false ? 0 : (int) $v;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Creates or upgrades the schema. Safe to call repeatedly and from several
 * processes at once (upgrades run inside an exclusive transaction).
 */
function db_migrate(): void
{
    $pdo = db();
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- One row per e-mail address, with its consent / audit trail.
CREATE TABLE IF NOT EXISTS subscribers (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
    status        TEXT NOT NULL DEFAULT 'pending',   -- pending | active
    general_list  INTEGER NOT NULL DEFAULT 0,        -- 1 = on the general list (manual campaigns)
    created_at    INTEGER NOT NULL,
    confirmed_at  INTEGER,
    ip            TEXT NOT NULL DEFAULT '',
    consent_text  TEXT NOT NULL DEFAULT '',
    signup_lists  TEXT NOT NULL DEFAULT '',          -- snapshot of the selections made at signup
    source        TEXT NOT NULL DEFAULT 'form',      -- form | import | admin
    updated_at    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_subscribers_status ON subscribers(status);

CREATE TABLE IF NOT EXISTS feeds (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT NOT NULL,
    url               TEXT NOT NULL,
    frequency         INTEGER NOT NULL DEFAULT 60,   -- minutes between checks
    active            INTEGER NOT NULL DEFAULT 1,
    to_general        INTEGER NOT NULL DEFAULT 0,    -- 1 = also sent to the general list
    baseline_done     INTEGER NOT NULL DEFAULT 0,
    created_at        INTEGER NOT NULL,
    last_checked_at   INTEGER,
    last_success_at   INTEGER,
    last_error        TEXT NOT NULL DEFAULT '',
    error_count       INTEGER NOT NULL DEFAULT 0,
    etag              TEXT NOT NULL DEFAULT '',
    last_modified     TEXT NOT NULL DEFAULT '',
    feed_title        TEXT NOT NULL DEFAULT ''
);

-- Join table: which subscriber follows which feed.
CREATE TABLE IF NOT EXISTS subscriber_feeds (
    subscriber_id INTEGER NOT NULL REFERENCES subscribers(id) ON DELETE CASCADE,
    feed_id       INTEGER NOT NULL REFERENCES feeds(id) ON DELETE CASCADE,
    created_at    INTEGER NOT NULL,
    PRIMARY KEY (subscriber_id, feed_id)
);
CREATE INDEX IF NOT EXISTS idx_subfeeds_feed ON subscriber_feeds(feed_id);

-- Every item ever seen per feed (the "already seen" memory).
CREATE TABLE IF NOT EXISTS feed_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    feed_id       INTEGER NOT NULL REFERENCES feeds(id) ON DELETE CASCADE,
    item_key      TEXT NOT NULL,
    title         TEXT NOT NULL DEFAULT '',
    link          TEXT NOT NULL DEFAULT '',
    summary       TEXT NOT NULL DEFAULT '',
    published_at  INTEGER,
    first_seen_at INTEGER NOT NULL,
    last_seen_at  INTEGER NOT NULL,
    notified      INTEGER NOT NULL DEFAULT 0,        -- 0 baseline/skipped, 1 queued for sending
    UNIQUE (feed_id, item_key)
);

-- Single-use tokens (magic links, double opt-in confirmation). Only hashes are stored.
CREATE TABLE IF NOT EXISTS tokens (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash    TEXT NOT NULL UNIQUE,
    purpose       TEXT NOT NULL,                     -- admin | manage | confirm
    email         TEXT NOT NULL,
    subscriber_id INTEGER,
    created_at    INTEGER NOT NULL,
    expires_at    INTEGER NOT NULL,
    used_at       INTEGER
);
CREATE INDEX IF NOT EXISTS idx_tokens_expires ON tokens(expires_at);

CREATE TABLE IF NOT EXISTS rate_events (
    bucket     TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rate_bucket ON rate_events(bucket, created_at);

-- A campaign is one send job: a manual message or one feed-check cycle.
CREATE TABLE IF NOT EXISTS campaigns (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    kind        TEXT NOT NULL,                       -- manual | feed
    subject     TEXT NOT NULL,
    body_html   TEXT NOT NULL DEFAULT '',
    body_text   TEXT NOT NULL DEFAULT '',
    audience    TEXT NOT NULL DEFAULT 'general',     -- general | all | feed:<id> | feeds
    status      TEXT NOT NULL DEFAULT 'sending',     -- sending | paused | done | cancelled
    total       INTEGER NOT NULL DEFAULT 0,          -- counters cached from the queue table
    sent        INTEGER NOT NULL DEFAULT 0,
    failed      INTEGER NOT NULL DEFAULT 0,
    created_at  INTEGER NOT NULL,
    finished_at INTEGER
);

-- One row per individual e-mail to send.
CREATE TABLE IF NOT EXISTS queue (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id     INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    subscriber_id   INTEGER NOT NULL,
    email           TEXT NOT NULL,
    payload         TEXT NOT NULL DEFAULT '',        -- JSON list of feed_items ids for feed digests
    status          TEXT NOT NULL DEFAULT 'pending', -- pending | sending | sent | failed | skipped
    attempts        INTEGER NOT NULL DEFAULT 0,
    next_attempt_at INTEGER NOT NULL DEFAULT 0,
    error           TEXT NOT NULL DEFAULT '',
    updated_at      INTEGER NOT NULL,
    sent_at         INTEGER
);
CREATE INDEX IF NOT EXISTS idx_queue_status ON queue(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_queue_campaign ON queue(campaign_id, status);

-- Every e-mail actually handed to the SMTP server (for the hourly limit).
CREATE TABLE IF NOT EXISTS send_log (
    sent_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_send_log ON send_log(sent_at);
SQL
    );
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $current = max(1, db_schema_version()); // the statements above are version 1
        foreach (db_migrations() as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
            $current = $version;
        }
        $pdo->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')->execute(['schema_version', (string) $current]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Upgrades an installed database after new files were uploaded. */
function db_upgrade_if_needed(): void
{
    if (db_schema_version() < NM_SCHEMA_VERSION) {
        db_migrate();
        nm_log('app', 'Database upgraded to schema version ' . NM_SCHEMA_VERSION);
    }
}

/** Periodic cleanup, called from the cron scripts. */
function db_housekeeping(): void
{
    $now = nm_now();
    db_exec('DELETE FROM tokens WHERE expires_at < ? OR (used_at IS NOT NULL AND used_at < ?)', [$now - 86400, $now - 86400]);
    db_exec('DELETE FROM rate_events WHERE created_at < ?', [$now - 86400]);
    db_exec('DELETE FROM send_log WHERE sent_at < ?', [$now - 7 * 86400]);
    // Unconfirmed (double opt-in) signups are purged after N days (data minimisation).
    $days = setting_int('pending_days', 1, 3650);
    db_exec("DELETE FROM subscribers WHERE status = 'pending' AND created_at < ?", [$now - $days * 86400]);
    // Finished campaigns keep their stats; the per-recipient rows are trimmed after 90 days.
    db_exec("DELETE FROM queue WHERE status IN ('sent','skipped') AND updated_at < ?", [$now - 90 * 86400]);
    db_exec('DELETE FROM bounce_log WHERE created_at < ?', [$now - 365 * 86400]);
}
