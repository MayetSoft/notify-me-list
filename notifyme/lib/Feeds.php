<?php
/**
 * Feed sources: CRUD, periodic checks, "already seen" memory, and hand-off
 * of new items to the sending queue (one digest per subscriber per cycle).
 */
final class Feeds
{
    /** Allowed check frequencies, in minutes. */
    const FREQUENCIES = [15, 30, 60, 180, 360, 720, 1440];

    /** If every item of an already-known feed looks new, treat it as a format/ID change. */
    const REBASELINE_MIN_ITEMS = 5;

    /** Items that disappeared from the feed are forgotten after this many days. */
    const FORGET_AFTER_DAYS = 120;

    public static function frequencyLabel(int $minutes): string
    {
        return t('feed.freq_' . $minutes) !== 'feed.freq_' . $minutes ? t('feed.freq_' . $minutes) : t('feed.freq_minutes', ['n' => $minutes]);
    }

    public static function find(int $id): ?array
    {
        return db_one('SELECT * FROM feeds WHERE id = ?', [$id]);
    }

    /** All feeds with subscriber and item counts. */
    public static function all(): array
    {
        return db_all(
            "SELECT f.*,
                (SELECT COUNT(*) FROM subscriber_feeds sf JOIN subscribers s ON s.id = sf.subscriber_id
                  WHERE sf.feed_id = f.id AND s.status = 'active') AS subscriber_count,
                (SELECT COUNT(*) FROM feed_items fi WHERE fi.feed_id = f.id) AS item_count
             FROM feeds f ORDER BY f.name COLLATE NOCASE"
        );
    }

    /** Non-paused feeds, for the public form. */
    public static function active(): array
    {
        return db_all('SELECT id, name FROM feeds WHERE active = 1 ORDER BY name COLLATE NOCASE');
    }

    private static function validate(string $name, string $url, int $frequency): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException(t('feed.err_name'));
        }
        $parts = FeedFetcher::validateUrl($url);
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            $frequency = 60;
        }
        return [$name, $parts['url'], $frequency];
    }

    /**
     * Adds a feed. The URL is fetched immediately: this validates it and
     * records the current items as the baseline (nothing is e-mailed).
     * Returns ['id' => int, 'items' => n].
     */
    public static function create(string $name, string $url, int $frequency, bool $toGeneral): array
    {
        list($name, $url, $frequency) = self::validate($name, $url, $frequency);
        $res = FeedFetcher::fetch($url);
        $parsed = FeedParser::parse($res['body'], $res['url']);
        $now = nm_now();
        db_exec(
            'INSERT INTO feeds (name, url, frequency, active, to_general, baseline_done, created_at, last_checked_at, last_success_at, etag, last_modified, feed_title)
             VALUES (?, ?, ?, 1, ?, 0, ?, ?, ?, ?, ?, ?)',
            [$name, $url, $frequency, $toGeneral ? 1 : 0, $now, $now, $now, $res['etag'], $res['last_modified'], mb_substr($parsed['title'], 0, 200)]
        );
        $id = (int) db()->lastInsertId();
        self::recordItems(self::find($id), $parsed['items']);
        nm_log('feeds', 'Feed #' . $id . ' added (' . $url . '), baseline of ' . count($parsed['items']) . ' items');
        return ['id' => $id, 'items' => count($parsed['items'])];
    }

    public static function update(int $id, string $name, string $url, int $frequency, bool $toGeneral): void
    {
        $feed = self::find($id);
        if (!$feed) {
            throw new InvalidArgumentException(t('feed.not_found'));
        }
        list($name, $url, $frequency) = self::validate($name, $url, $frequency);
        if ($url !== $feed['url']) {
            // New address: re-validate by fetching and start a new baseline.
            $res = FeedFetcher::fetch($url);
            $parsed = FeedParser::parse($res['body'], $res['url']);
            db_tx(function (PDO $pdo) use ($id, $url, $res, $parsed) {
                $pdo->prepare('DELETE FROM feed_items WHERE feed_id = ?')->execute([$id]);
                $pdo->prepare("UPDATE feeds SET url = ?, baseline_done = 0, etag = ?, last_modified = ?, feed_title = ?, last_error = '', error_count = 0 WHERE id = ?")
                    ->execute([$url, $res['etag'], $res['last_modified'], mb_substr($parsed['title'], 0, 200), $id]);
            });
            self::recordItems(self::find($id), $parsed['items']);
        }
        db_exec('UPDATE feeds SET name = ?, frequency = ?, to_general = ? WHERE id = ?', [$name, $frequency, $toGeneral ? 1 : 0, $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        db_exec('UPDATE feeds SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    public static function delete(int $id): void
    {
        db_tx(function (PDO $pdo) use ($id) {
            $pdo->prepare('DELETE FROM subscriber_feeds WHERE feed_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM feed_items WHERE feed_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM feeds WHERE id = ?')->execute([$id]);
        });
        nm_log('feeds', 'Feed #' . $id . ' deleted');
    }

    /**
     * Stores the items of a fetched feed and returns the ids of the rows that
     * must be e-mailed. Nothing is e-mailed on the first check (baseline) or
     * when the whole feed suddenly looks new (GUID/format change).
     */
    public static function recordItems(array $feed, array $items): array
    {
        $feedId = (int) $feed['id'];
        $now = nm_now();

        // De-duplicate within the document itself.
        $byKey = [];
        foreach ($items as $it) {
            if (!isset($byKey[$it['key']])) {
                $byKey[$it['key']] = $it;
            }
        }
        if (!$byKey) {
            db_exec('UPDATE feeds SET baseline_done = 1 WHERE id = ?', [$feedId]);
            return [];
        }

        $known = [];
        foreach (array_chunk(array_keys($byKey), 400) as $chunk) {
            $rows = db_all('SELECT item_key FROM feed_items WHERE feed_id = ? AND item_key IN (' . db_placeholders($chunk) . ')', array_merge([$feedId], $chunk));
            foreach ($rows as $r) {
                $known[$r['item_key']] = true;
            }
        }
        $new = array_diff_key($byKey, $known);

        $baseline = (int) $feed['baseline_done'] === 0;
        $rebaseline = !$baseline && count($byKey) >= self::REBASELINE_MIN_ITEMS && count($new) === count($byKey);
        if ($rebaseline) {
            nm_log('feeds', 'Feed #' . $feedId . ': every item looks new (' . count($new) . '), treated as an ID/format change — recorded without sending.');
        }

        // Newest first, then keep at most feed_max_items to e-mail.
        uasort($new, function ($a, $b) {
            return (int) $b['published_at'] - (int) $a['published_at'];
        });
        $maxToSend = setting_int('feed_max_items', 1, 50);

        $toSend = [];
        db_tx(function (PDO $pdo) use ($feedId, $now, $known, $new, $baseline, $rebaseline, $maxToSend, &$toSend) {
            if ($known) {
                foreach (array_chunk(array_keys($known), 400) as $chunk) {
                    $pdo->prepare('UPDATE feed_items SET last_seen_at = ? WHERE feed_id = ? AND item_key IN (' . db_placeholders($chunk) . ')')
                        ->execute(array_merge([$now, $feedId], $chunk));
                }
            }
            $ins = $pdo->prepare(
                'INSERT OR IGNORE INTO feed_items (feed_id, item_key, title, link, summary, published_at, first_seen_at, last_seen_at, notified)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $n = 0;
            foreach ($new as $key => $it) {
                $notify = !$baseline && !$rebaseline && $n < $maxToSend;
                $ins->execute([$feedId, $key, $it['title'], $it['link'], $it['summary'], $it['published_at'], $now, $now, $notify ? 1 : 0]);
                if ($notify && $ins->rowCount() === 1) {
                    $toSend[] = (int) $pdo->lastInsertId();
                    $n++;
                }
            }
            $pdo->prepare('UPDATE feeds SET baseline_done = 1 WHERE id = ?')->execute([$feedId]);
            // Forget items gone from the feed for a long time (keeps the table small).
            $pdo->prepare('DELETE FROM feed_items WHERE feed_id = ? AND last_seen_at < ?')->execute([$feedId, $now - self::FORGET_AFTER_DAYS * 86400]);
        });
        return $toSend;
    }

    /** Fetches + parses one feed and records its items. Returns ids of items to e-mail. */
    public static function checkOne(array $feed): array
    {
        $now = nm_now();
        db_exec('UPDATE feeds SET last_checked_at = ? WHERE id = ?', [$now, $feed['id']]);
        $res = FeedFetcher::fetch($feed['url'], (string) $feed['etag'], (string) $feed['last_modified']);
        if ($res['status'] === 304) {
            db_exec("UPDATE feeds SET last_success_at = ?, last_error = '', error_count = 0 WHERE id = ?", [$now, $feed['id']]);
            return [];
        }
        $parsed = FeedParser::parse($res['body'], $res['url']);
        $ids = self::recordItems($feed, $parsed['items']);
        db_exec(
            "UPDATE feeds SET last_success_at = ?, last_error = '', error_count = 0, etag = ?, last_modified = ?, feed_title = ? WHERE id = ?",
            [$now, $res['etag'], $res['last_modified'], mb_substr($parsed['title'], 0, 200), $feed['id']]
        );
        return $ids;
    }

    /**
     * Checks every due feed (or one feed / all feeds when forced), each in its
     * own try/catch, then queues ONE digest per subscriber for all the new
     * items found in this cycle. Returns a report.
     */
    public static function checkDue(bool $force = false, ?int $onlyId = null): array
    {
        $report = ['checked' => 0, 'errors' => 0, 'new_items' => 0, 'campaign_id' => null, 'lines' => []];
        $lock = nm_lock('feeds');
        if (!$lock) {
            $report['lines'][] = t('feed.check_locked');
            return $report;
        }
        try {
            $now = nm_now();
            $feeds = $onlyId !== null
                ? db_all('SELECT * FROM feeds WHERE id = ?', [$onlyId])
                : db_all('SELECT * FROM feeds WHERE active = 1 ORDER BY id');
            $newByFeed = [];
            foreach ($feeds as $feed) {
                // 60 s of tolerance so a 15-min feed is not skipped by a 15-min cron that fires a bit early.
                $due = $feed['last_checked_at'] === null || ((int) $feed['last_checked_at'] + (int) $feed['frequency'] * 60 - 60) <= $now;
                if (!$force && !$due) {
                    continue;
                }
                $report['checked']++;
                try {
                    $ids = self::checkOne($feed);
                    if ($ids) {
                        $newByFeed[(int) $feed['id']] = $ids;
                        $report['new_items'] += count($ids);
                    }
                    $report['lines'][] = $feed['name'] . ': ' . t('feed.check_ok', ['n' => count($ids)]);
                } catch (Throwable $e) {
                    $report['errors']++;
                    $msg = mb_substr($e->getMessage(), 0, 500);
                    db_exec('UPDATE feeds SET last_error = ?, error_count = error_count + 1 WHERE id = ?', [$msg, $feed['id']]);
                    nm_log('feeds', 'Feed #' . $feed['id'] . ' (' . $feed['url'] . ') failed: ' . $msg);
                    $report['lines'][] = $feed['name'] . ': ' . t('feed.check_error', ['error' => $msg]);
                }
            }
            if ($newByFeed) {
                $report['campaign_id'] = Queue::enqueueFeedDigest($newByFeed);
            }
        } finally {
            nm_unlock($lock);
        }
        return $report;
    }
}

/**
 * Non-blocking exclusive lock on data/<name>.lock (prevents overlapping cron
 * runs and double sending). Returns the handle, or null if already locked.
 */
function nm_lock(string $name)
{
    $fh = @fopen(NM_DATA . '/' . $name . '.lock', 'c');
    if (!$fh) {
        return null;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return null;
    }
    return $fh;
}

function nm_unlock($fh): void
{
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
