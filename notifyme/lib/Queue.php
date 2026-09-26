<?php
/**
 * Sending queue shared by manual campaigns and feed digests.
 *
 * Every e-mail is one row in `queue` and is sent individually (never BCC)
 * with the recipient's own unsubscribe link. Queue::process() is the single
 * sending function: it is called by the admin "sending" page (AJAX, chunk
 * by chunk), by cron/send-queue.php and at the end of cron/check-feeds.php.
 * A file lock guarantees two processes never send at the same time.
 */
final class Queue
{
    const MAX_ATTEMPTS = 3;
    /** Stop a run after this many recipient failures in a row (something is wrong server-side). */
    const MAX_CONSECUTIVE_FAILURES = 5;
    /** Pause after the SMTP server reported a sending limit, in seconds. */
    const HOST_LIMIT_PAUSE = 3600;
    const STALE_AFTER = 600; // a row stuck in "sending" for 10 min is considered interrupted

    // --- Audiences -------------------------------------------------------------

    /** SQL WHERE (on alias s) + params for an audience code. */
    private static function audienceWhere(string $audience): array
    {
        if ($audience === 'all') {
            return ["s.status = 'active'", []];
        }
        if (preg_match('/^feed:(\d+)$/', $audience, $m)) {
            return ["s.status = 'active' AND EXISTS (SELECT 1 FROM subscriber_feeds sf WHERE sf.subscriber_id = s.id AND sf.feed_id = ?)", [(int) $m[1]]];
        }
        return ["s.status = 'active' AND s.general_list = 1", []];
    }

    public static function countAudience(string $audience): int
    {
        list($w, $p) = self::audienceWhere($audience);
        return (int) db_value('SELECT COUNT(*) FROM subscribers s WHERE ' . $w, $p);
    }

    public static function audienceLabel(string $audience): string
    {
        if ($audience === 'all') {
            return t('send.audience_all');
        }
        if ($audience === 'feeds') {
            return t('send.audience_feeds');
        }
        if (preg_match('/^feed:(\d+)$/', $audience, $m)) {
            $f = Feeds::find((int) $m[1]);
            return t('send.audience_feed', ['name' => $f ? $f['name'] : '#' . $m[1]]);
        }
        return nm_general_label();
    }

    /** Is the subscriber still part of the campaign audience at send time? */
    private static function stillInAudience(string $audience, array $sub): bool
    {
        if ($sub['status'] !== 'active') {
            return false;
        }
        if ($audience === 'general') {
            return (int) $sub['general_list'] === 1;
        }
        if (preg_match('/^feed:(\d+)$/', $audience, $m)) {
            return (bool) db_value('SELECT 1 FROM subscriber_feeds WHERE subscriber_id = ? AND feed_id = ?', [$sub['id'], (int) $m[1]]);
        }
        return true;
    }

    // --- Creating campaigns ------------------------------------------------------

    /** Creates a manual campaign and one queue row per recipient. Returns its id. */
    public static function createManual(string $subject, string $html, string $text, string $audience): int
    {
        return db_tx(function (PDO $pdo) use ($subject, $html, $text, $audience) {
            $now = nm_now();
            $pdo->prepare("INSERT INTO campaigns (kind, subject, body_html, body_text, audience, status, created_at) VALUES ('manual', ?, ?, ?, ?, 'sending', ?)")
                ->execute([$subject, $html, $text, $audience, $now]);
            $id = (int) $pdo->lastInsertId();
            list($w, $p) = self::audienceWhere($audience);
            $st = $pdo->prepare("INSERT INTO queue (campaign_id, subscriber_id, email, payload, status, updated_at)
                SELECT ?, s.id, s.email, '', 'pending', ? FROM subscribers s WHERE " . $w . ' ORDER BY s.id');
            $st->execute(array_merge([$id, $now], $p));
            $pdo->prepare('UPDATE campaigns SET total = ? WHERE id = ?')->execute([$st->rowCount(), $id]);
            if ($st->rowCount() === 0) {
                $pdo->prepare("UPDATE campaigns SET status = 'done', finished_at = ? WHERE id = ?")->execute([$now, $id]);
            }
            return $id;
        });
    }

    /**
     * Queues one digest per subscriber for the new items of this cycle.
     * $newByFeed: [feed_id => [feed_item ids]]. Returns the campaign id or null.
     */
    public static function enqueueFeedDigest(array $newByFeed): ?int
    {
        $feedIds = array_keys($newByFeed);
        $feeds = db_all('SELECT id, name, to_general FROM feeds WHERE id IN (' . db_placeholders($feedIds) . ')', $feedIds);
        $recipients = []; // subscriber id => [email, [feed ids]]
        foreach (db_all(
            "SELECT sf.subscriber_id, sf.feed_id, s.email FROM subscriber_feeds sf JOIN subscribers s ON s.id = sf.subscriber_id
             WHERE s.status = 'active' AND sf.feed_id IN (" . db_placeholders($feedIds) . ')',
            $feedIds
        ) as $r) {
            $recipients[$r['subscriber_id']]['email'] = $r['email'];
            $recipients[$r['subscriber_id']]['feeds'][(int) $r['feed_id']] = true;
        }
        $generalFeeds = [];
        foreach ($feeds as $f) {
            if ((int) $f['to_general'] === 1) {
                $generalFeeds[] = (int) $f['id'];
            }
        }
        if ($generalFeeds) {
            foreach (db_all("SELECT id, email FROM subscribers WHERE status = 'active' AND general_list = 1") as $r) {
                $recipients[$r['id']]['email'] = $r['email'];
                foreach ($generalFeeds as $fid) {
                    $recipients[$r['id']]['feeds'][$fid] = true;
                }
            }
        }
        $names = [];
        foreach ($feeds as $f) {
            $names[] = $f['name'];
        }
        $count = 0;
        foreach ($newByFeed as $ids) {
            $count += count($ids);
        }
        $label = t('queue.feed_campaign_label', ['feeds' => implode(', ', $names), 'n' => $count]);

        return db_tx(function (PDO $pdo) use ($recipients, $newByFeed, $label) {
            $now = nm_now();
            $pdo->prepare("INSERT INTO campaigns (kind, subject, audience, status, created_at) VALUES ('feed', ?, 'feeds', 'sending', ?)")->execute([$label, $now]);
            $cid = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO queue (campaign_id, subscriber_id, email, payload, status, updated_at) VALUES (?, ?, ?, ?, 'pending', ?)");
            $n = 0;
            foreach ($recipients as $sid => $r) {
                $items = [];
                foreach (array_keys($r['feeds']) as $fid) {
                    if (isset($newByFeed[$fid])) {
                        $items = array_merge($items, $newByFeed[$fid]);
                    }
                }
                if ($items) {
                    $ins->execute([$cid, $sid, $r['email'], json_encode(array_values($items)), $now]);
                    $n++;
                }
            }
            $pdo->prepare('UPDATE campaigns SET total = ? WHERE id = ?')->execute([$n, $cid]);
            if ($n === 0) {
                $pdo->prepare("UPDATE campaigns SET status = 'done', finished_at = ? WHERE id = ?")->execute([$now, $cid]);
            }
            nm_log('queue', 'Feed digest campaign #' . $cid . ' queued for ' . $n . ' subscribers');
            return $cid;
        });
    }

    // --- Rendering -----------------------------------------------------------------

    /**
     * Builds [subject, html, text, headers] for one queue row, or null when
     * there is nothing (left) to send to this subscriber.
     */
    public static function render(array $campaign, array $row, array $sub): ?array
    {
        $unsub = Tokens::unsubscribeUrl($sub);
        $headers = Mailer::unsubscribeHeaders($unsub, true);
        if ($campaign['kind'] === 'feed') {
            $d = self::renderDigest($row, $sub, $unsub);
            return $d ? [$d[0], $d[1], $d[2], $headers] : null;
        }
        if (!self::stillInAudience($campaign['audience'], $sub)) {
            return null;
        }
        list($html, $text) = Mailer::wrap($campaign['subject'], $campaign['body_html'], $campaign['body_text'], $sub, $unsub);
        return [$campaign['subject'], $html, $text, $headers];
    }

    /** Feed digest for one subscriber, grouped by feed. Returns [subject, html, text] or null. */
    public static function renderDigest(array $row, array $sub, string $unsubscribeUrl): ?array
    {
        $ids = json_decode((string) $row['payload'], true);
        if (!is_array($ids) || !$ids) {
            return null;
        }
        $ids = array_map('intval', $ids);
        $items = db_all(
            'SELECT fi.*, f.name AS feed_name, f.to_general FROM feed_items fi JOIN feeds f ON f.id = fi.feed_id WHERE fi.id IN (' . db_placeholders($ids) . ')',
            $ids
        );
        // Respect changes made since the digest was queued (feed removed in self-service...).
        $follows = array_flip(Subscribers::feedIds((int) $sub['id']));
        $general = (int) $sub['general_list'] === 1;
        $groups = [];
        foreach ($items as $it) {
            if (!isset($follows[$it['feed_id']]) && !($general && (int) $it['to_general'] === 1)) {
                continue;
            }
            $groups[$it['feed_id']]['feed'] = $it['feed_name'];
            $groups[$it['feed_id']]['items'][] = [
                'title' => $it['title'],
                'link' => nm_safe_url($it['link']),
                'summary' => $it['summary'],
                'date' => $it['published_at'] ? nm_format_date($it['published_at'], false) : '',
                'ts' => (int) ($it['published_at'] ?: $it['first_seen_at']),
            ];
        }
        if (!$groups) {
            return null;
        }
        $total = 0;
        foreach ($groups as &$g) {
            usort($g['items'], function ($a, $b) {
                return $b['ts'] - $a['ts'];
            });
            $total += count($g['items']);
        }
        unset($g);
        uasort($groups, function ($a, $b) {
            return strcasecmp($a['feed'], $b['feed']);
        });
        $groups = array_values($groups);

        if ($total === 1) {
            $subject = $groups[0]['feed'] . ' : ' . $groups[0]['items'][0]['title'];
        } else {
            $subject = strtr((string) setting('feed_subject', ''), ['{site}' => nm_site_name(), '{count}' => (string) $total]);
            if (trim($subject) === '') {
                $subject = t('queue.digest_subject', ['site' => nm_site_name(), 'count' => $total]);
            }
        }
        $vars = [
            'siteName' => nm_site_name(),
            'siteUrl' => nm_abs_url(''),
            'subject' => $subject,
            'intro' => t('digest.intro', ['count' => $total]),
            'groups' => $groups,
            'unsubscribeUrl' => $unsubscribeUrl,
            'manageUrl' => nm_abs_url('manage.php'),
            'email' => $sub['email'],
        ];
        return [
            mb_substr($subject, 0, 200),
            nm_render(NM_ROOT . '/templates/email/feed-digest.html.php', $vars),
            nm_render(NM_ROOT . '/templates/email/feed-digest.txt.php', $vars),
        ];
    }

    // --- Processing -----------------------------------------------------------------

    public static function sentLastHour(): int
    {
        return (int) db_value('SELECT COUNT(*) FROM send_log WHERE sent_at > ?', [nm_now() - 3600]);
    }

    public static function sentLastDay(): int
    {
        return (int) db_value('SELECT COUNT(*) FROM send_log WHERE sent_at > ?', [nm_now() - 86400]);
    }

    /** Timestamp until which sending is paused because the host refused mail for quota reasons (0 = not paused). */
    public static function pausedUntil(): int
    {
        $t = (int) setting('send_paused_until', 0);
        return $t > nm_now() ? $t : 0;
    }

    public static function clearPause(): void
    {
        setting_set('send_paused_until', '0');
        setting_set('send_paused_reason', '');
    }

    /**
     * Sends up to $max e-mails within $timeBudget seconds.
     * Returns ['sent','failed','skipped','remaining','busy','throttled','host_limit','smtp_error'].
     *
     * Rate limiting, in order: a pause after the host reported a quota
     * refusal, the daily cap, the hourly cap (both counted on every e-mail
     * actually sent, transactional ones included), the batch size ($max),
     * and the delay between two e-mails.
     */
    public static function process(int $max, int $timeBudget, ?int $campaignId = null): array
    {
        $r = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0, 'busy' => false, 'throttled' => false, 'host_limit' => '', 'smtp_error' => ''];
        if (self::pausedUntil() > 0) {
            $r['throttled'] = true;
            $r['host_limit'] = (string) setting('send_paused_reason', '');
            $r['remaining'] = self::remaining($campaignId);
            return $r;
        }
        $lock = nm_lock('queue');
        if (!$lock) {
            $r['busy'] = true;
            $r['remaining'] = self::remaining($campaignId);
            return $r;
        }
        $touched = [];
        try {
            $now = nm_now();
            // Rows left in "sending" by a crashed run: we cannot know if the
            // server accepted them, so they are marked failed (retryable) rather
            // than silently re-sent.
            db_exec("UPDATE queue SET status = 'failed', error = 'interrupted', updated_at = ? WHERE status = 'sending' AND updated_at < ?", [$now, $now - self::STALE_AFTER]);

            $maxHour = setting_int('max_per_hour', 0, 1000000);
            $maxDay = setting_int('max_per_day', 0, 10000000);
            $delayUs = setting_int('send_delay_ms', 0, 60000) * 1000;
            $sentHour = $maxHour > 0 ? self::sentLastHour() : 0;
            $sentDay = $maxDay > 0 ? self::sentLastDay() : 0;
            $start = microtime(true);
            $campaigns = [];
            $done = 0;
            $failStreak = 0;

            while ($done < $max && (microtime(true) - $start) < $timeBudget) {
                if (($maxHour > 0 && $sentHour >= $maxHour) || ($maxDay > 0 && $sentDay >= $maxDay)) {
                    $r['throttled'] = true;
                    break;
                }
                $sql = "SELECT q.* FROM queue q JOIN campaigns c ON c.id = q.campaign_id
                        WHERE q.status = 'pending' AND q.next_attempt_at <= ? AND c.status = 'sending'";
                $params = [nm_now()];
                if ($campaignId !== null) {
                    $sql .= ' AND q.campaign_id = ?';
                    $params[] = $campaignId;
                }
                $row = db_one($sql . ' ORDER BY q.campaign_id, q.id LIMIT 1', $params);
                if (!$row) {
                    break;
                }
                if (db_exec("UPDATE queue SET status = 'sending', updated_at = ? WHERE id = ? AND status = 'pending'", [nm_now(), $row['id']]) !== 1) {
                    continue;
                }
                $cid = (int) $row['campaign_id'];
                $touched[$cid] = true;
                if (!isset($campaigns[$cid])) {
                    $campaigns[$cid] = db_one('SELECT * FROM campaigns WHERE id = ?', [$cid]);
                }
                $sub = Subscribers::find((int) $row['subscriber_id']);
                $msg = null;
                try {
                    $msg = $sub ? self::render($campaigns[$cid], $row, $sub) : null;
                } catch (Throwable $e) {
                    self::mark($row['id'], 'failed', 'render: ' . $e->getMessage());
                    $r['failed']++;
                    $done++;
                    continue;
                }
                if ($msg === null) {
                    self::mark($row['id'], 'skipped', $sub ? 'not in audience anymore' : 'unsubscribed');
                    $r['skipped']++;
                    continue;
                }
                if ($done > 0 && $delayUs > 0) {
                    usleep($delayUs);
                }
                try {
                    Mailer::send($sub['email'], $msg[0], $msg[1], $msg[2], $msg[3]);
                    db_exec("UPDATE queue SET status = 'sent', sent_at = ?, updated_at = ?, error = '' WHERE id = ?", [nm_now(), nm_now(), $row['id']]);
                    $r['sent']++;
                    $sentHour++;
                    $sentDay++;
                    $failStreak = 0;
                } catch (SmtpException $e) {
                    if ($e->isRateLimit()) {
                        // The host's own limit: not this recipient's fault. Put the
                        // e-mail back, pause ALL sending, resume automatically later.
                        db_exec("UPDATE queue SET status = 'pending', updated_at = ?, error = ? WHERE id = ?", [nm_now(), mb_substr($e->getMessage(), 0, 500), $row['id']]);
                        setting_set('send_paused_until', (string) (nm_now() + self::HOST_LIMIT_PAUSE));
                        setting_set('send_paused_reason', mb_substr($e->getMessage(), 0, 500));
                        $r['throttled'] = true;
                        $r['host_limit'] = $e->getMessage();
                        nm_log('queue', 'Host sending limit reached, sending paused for ' . (self::HOST_LIMIT_PAUSE / 60) . ' min: ' . $e->getMessage());
                        break;
                    }
                    if ($e->connectionLevel) {
                        // Server unreachable / auth refused: put the row back and stop this run.
                        db_exec("UPDATE queue SET status = 'pending', updated_at = ?, error = ? WHERE id = ?", [nm_now(), mb_substr($e->getMessage(), 0, 500), $row['id']]);
                        $r['smtp_error'] = $e->getMessage();
                        nm_log('queue', 'SMTP error, run stopped: ' . $e->getMessage());
                        break;
                    }
                    $attempts = (int) $row['attempts'] + 1;
                    if ($e->isTemporary() && $attempts < self::MAX_ATTEMPTS) {
                        db_exec(
                            "UPDATE queue SET status = 'pending', attempts = ?, next_attempt_at = ?, error = ?, updated_at = ? WHERE id = ?",
                            [$attempts, nm_now() + 600 * $attempts, mb_substr($e->getMessage(), 0, 500), nm_now(), $row['id']]
                        );
                    } else {
                        db_exec("UPDATE queue SET status = 'failed', attempts = ?, error = ?, updated_at = ? WHERE id = ?", [$attempts, mb_substr($e->getMessage(), 0, 500), nm_now(), $row['id']]);
                        $r['failed']++;
                    }
                    nm_log('queue', 'Send to ' . $sub['email'] . ' failed: ' . $e->getMessage());
                    // Several refusals in a row usually mean an account-level problem
                    // worded in a way we do not recognise: stop instead of burning the list.
                    if (++$failStreak >= self::MAX_CONSECUTIVE_FAILURES) {
                        $r['smtp_error'] = t('queue.too_many_failures', ['n' => $failStreak, 'error' => $e->getMessage()]);
                        nm_log('queue', 'Run stopped after ' . $failStreak . ' consecutive failures');
                        $done++;
                        break;
                    }
                } catch (Throwable $e) {
                    self::mark($row['id'], 'failed', $e->getMessage());
                    $r['failed']++;
                    nm_log('queue', 'Send to ' . $sub['email'] . ' failed: ' . $e->getMessage());
                }
                $done++;
            }
            Mailer::closeConnection();
            self::finalize(array_keys($touched));
        } finally {
            nm_unlock($lock);
        }
        $r['remaining'] = self::remaining($campaignId);
        return $r;
    }

    private static function mark(int $id, string $status, string $error): void
    {
        db_exec('UPDATE queue SET status = ?, error = ?, updated_at = ? WHERE id = ?', [$status, mb_substr($error, 0, 500), nm_now(), $id]);
    }

    /** Pending rows (all campaigns or one), including ones waiting for a retry. */
    public static function remaining(?int $campaignId = null): int
    {
        if ($campaignId !== null) {
            return (int) db_value("SELECT COUNT(*) FROM queue WHERE campaign_id = ? AND status IN ('pending','sending')", [$campaignId]);
        }
        return (int) db_value("SELECT COUNT(*) FROM queue q JOIN campaigns c ON c.id = q.campaign_id WHERE q.status IN ('pending','sending') AND c.status = 'sending'");
    }

    /** Updates cached counters and closes finished campaigns. */
    public static function finalize(array $campaignIds): void
    {
        // Also close campaigns whose last rows were skipped by someone else.
        foreach (db_all("SELECT id FROM campaigns WHERE status = 'sending'") as $c) {
            $campaignIds[] = (int) $c['id'];
        }
        foreach (array_unique($campaignIds) as $cid) {
            self::refreshStats((int) $cid);
            $left = (int) db_value("SELECT COUNT(*) FROM queue WHERE campaign_id = ? AND status IN ('pending','sending')", [$cid]);
            if ($left === 0) {
                db_exec("UPDATE campaigns SET status = 'done', finished_at = ? WHERE id = ? AND status = 'sending'", [nm_now(), $cid]);
            }
        }
    }

    public static function refreshStats(int $cid): void
    {
        db_exec(
            "UPDATE campaigns SET
                total = (SELECT COUNT(*) FROM queue WHERE campaign_id = campaigns.id),
                sent = (SELECT COUNT(*) FROM queue WHERE campaign_id = campaigns.id AND status = 'sent'),
                failed = (SELECT COUNT(*) FROM queue WHERE campaign_id = campaigns.id AND status = 'failed')
             WHERE id = ?",
            [$cid]
        );
    }

    /** Live progress of one campaign. */
    public static function progress(int $cid): array
    {
        $c = db_one('SELECT * FROM campaigns WHERE id = ?', [$cid]);
        if (!$c) {
            return [];
        }
        $counts = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach (db_all('SELECT status, COUNT(*) AS n FROM queue WHERE campaign_id = ? GROUP BY status', [$cid]) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $total = array_sum($counts);
        if ($total === 0) {
            // Rows trimmed by housekeeping: use the cached counters.
            $counts['sent'] = (int) $c['sent'];
            $counts['failed'] = (int) $c['failed'];
            $total = (int) $c['total'];
        }
        $processed = $total - $counts['pending'] - $counts['sending'];
        return [
            'id' => $cid,
            'status' => $c['status'],
            'status_label' => t('campaign.status_' . $c['status']),
            'total' => $total,
            'sent' => $counts['sent'],
            'failed' => $counts['failed'],
            'skipped' => $counts['skipped'],
            'pending' => $counts['pending'] + $counts['sending'],
            'percent' => $total > 0 ? (int) floor($processed * 100 / $total) : 100,
        ];
    }

    // --- Admin actions -----------------------------------------------------------------

    public static function setStatus(int $cid, string $status): void
    {
        if ($status === 'cancelled') {
            db_exec("UPDATE queue SET status = 'skipped', error = 'cancelled', updated_at = ? WHERE campaign_id = ? AND status = 'pending'", [nm_now(), $cid]);
            db_exec("UPDATE campaigns SET status = 'cancelled', finished_at = ? WHERE id = ? AND status IN ('sending','paused')", [nm_now(), $cid]);
        } elseif ($status === 'paused') {
            db_exec("UPDATE campaigns SET status = 'paused' WHERE id = ? AND status = 'sending'", [$cid]);
        } elseif ($status === 'sending') {
            db_exec("UPDATE campaigns SET status = 'sending' WHERE id = ? AND status = 'paused'", [$cid]);
        }
        self::refreshStats($cid);
    }

    public static function retryFailed(int $cid): int
    {
        $n = db_exec("UPDATE queue SET status = 'pending', attempts = 0, next_attempt_at = 0, updated_at = ? WHERE campaign_id = ? AND status = 'failed'", [nm_now(), $cid]);
        if ($n > 0) {
            db_exec("UPDATE campaigns SET status = 'sending', finished_at = NULL WHERE id = ? AND status IN ('done','sending')", [$cid]);
        }
        self::refreshStats($cid);
        return $n;
    }

    public static function delete(int $cid): void
    {
        db_tx(function (PDO $pdo) use ($cid) {
            $pdo->prepare('DELETE FROM queue WHERE campaign_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM campaigns WHERE id = ?')->execute([$cid]);
        });
    }
}
