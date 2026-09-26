<?php
/**
 * Subscribers: the e-mail address + consent trail, and its links to the
 * general list (subscribers.general_list) and to feeds (subscriber_feeds).
 * Unsubscribing deletes the row; the join rows go with it (ON DELETE CASCADE).
 */
final class Subscribers
{
    public static function find(int $id): ?array
    {
        return db_one('SELECT * FROM subscribers WHERE id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return db_one('SELECT * FROM subscribers WHERE email = ?', [nm_normalize_email($email)]);
    }

    /** @return int[] */
    public static function feedIds(int $id): array
    {
        return array_map('intval', array_column(db_all('SELECT feed_id FROM subscriber_feeds WHERE subscriber_id = ?', [$id]), 'feed_id'));
    }

    /** Feeds a subscriber follows: [['id','name','active'], ...]. */
    public static function feeds(int $id): array
    {
        return db_all(
            'SELECT f.id, f.name, f.active FROM subscriber_feeds sf JOIN feeds f ON f.id = sf.feed_id WHERE sf.subscriber_id = ? ORDER BY f.name',
            [$id]
        );
    }

    /** Human readable list names (general list label + feed names). */
    public static function listNames(array $sub): array
    {
        return nm_describe_lists((int) $sub['general_list'] === 1, array_column(self::feeds((int) $sub['id']), 'name'));
    }

    /** Keeps only ids of existing feeds; if $activeOnly, only non-paused feeds. */
    public static function filterFeedIds(array $ids, bool $activeOnly = true): array
    {
        if (!$ids) {
            return [];
        }
        $sql = 'SELECT id FROM feeds WHERE id IN (' . db_placeholders($ids) . ')' . ($activeOnly ? ' AND active = 1' : '');
        return array_map('intval', array_column(db_all($sql, array_values($ids)), 'id'));
    }

    /** Replaces the list/feed memberships of a subscriber. */
    public static function setLists(int $id, bool $general, array $feedIds): void
    {
        db_tx(function (PDO $pdo) use ($id, $general, $feedIds) {
            $now = nm_now();
            $pdo->prepare('UPDATE subscribers SET general_list = ?, updated_at = ? WHERE id = ?')->execute([$general ? 1 : 0, $now, $id]);
            if ($feedIds) {
                $params = array_merge([$id], array_values($feedIds));
                $pdo->prepare('DELETE FROM subscriber_feeds WHERE subscriber_id = ? AND feed_id NOT IN (' . db_placeholders($feedIds) . ')')->execute($params);
            } else {
                $pdo->prepare('DELETE FROM subscriber_feeds WHERE subscriber_id = ?')->execute([$id]);
            }
            $ins = $pdo->prepare('INSERT OR IGNORE INTO subscriber_feeds (subscriber_id, feed_id, created_at) VALUES (?, ?, ?)');
            foreach ($feedIds as $fid) {
                $ins->execute([$id, (int) $fid, $now]);
            }
        });
    }

    /** Adds memberships without removing existing ones. */
    public static function addLists(int $id, bool $general, array $feedIds): void
    {
        $sub = self::find($id);
        if (!$sub) {
            return;
        }
        $current = self::feedIds($id);
        self::setLists($id, $general || (int) $sub['general_list'] === 1, array_values(array_unique(array_merge($current, $feedIds))));
    }

    public static function activate(int $id): void
    {
        db_exec("UPDATE subscribers SET status = 'active', confirmed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [nm_now(), nm_now(), $id]);
    }

    /** Full removal: subscriber row, feed links (cascade), pending tokens, queued mails. */
    public static function delete(int $id): void
    {
        $sub = self::find($id);
        if (!$sub) {
            return;
        }
        db_tx(function (PDO $pdo) use ($id, $sub) {
            $pdo->prepare('DELETE FROM subscriber_feeds WHERE subscriber_id = ?')->execute([$id]);
            $pdo->prepare("UPDATE queue SET status = 'skipped', error = 'unsubscribed', updated_at = ? WHERE subscriber_id = ? AND status = 'pending'")->execute([nm_now(), $id]);
            $pdo->prepare("DELETE FROM tokens WHERE purpose IN ('manage', 'confirm') AND (email = ? OR subscriber_id = ?)")->execute([$sub['email'], $id]);
            $pdo->prepare('DELETE FROM subscribers WHERE id = ?')->execute([$id]);
        });
        nm_log('subscribers', 'Deleted subscriber #' . $id);
    }

    /**
     * Public signup. Returns ['result' => created|resent|exists|throttled, 'mail_ok' => bool].
     * The page shows the same neutral message for created/exists so nobody can
     * discover whether an address is already subscribed.
     */
    public static function signup(string $email, bool $general, array $feedIds, string $ip): array
    {
        $email = nm_normalize_email($email);
        $feedIds = self::filterFeedIds($feedIds, true);
        $double = setting('optin_mode', 'single') === 'double';
        $now = nm_now();
        $listNames = nm_describe_lists($general, $feedIds ? array_column(db_all('SELECT name FROM feeds WHERE id IN (' . db_placeholders($feedIds) . ') ORDER BY name', $feedIds), 'name') : []);
        $consent = nm_consent_text();
        $snapshot = implode(', ', $listNames);

        $existing = self::findByEmail($email);
        if ($existing && $existing['status'] === 'active') {
            // Already subscribed: e-mail the owner a manage link instead of changing anything.
            $ok = Tokens::sendMagicLink('manage', $email, (int) $existing['id'], t('mail.already_subscribed_intro', ['site' => nm_site_name()]));
            return ['result' => 'exists', 'mail_ok' => $ok];
        }

        if ($existing) {
            $id = (int) $existing['id'];
            db_exec(
                'UPDATE subscribers SET ip = ?, consent_text = ?, signup_lists = ?, updated_at = ? WHERE id = ?',
                [$ip, $consent, $snapshot, $now, $id]
            );
            $result = 'resent';
        } else {
            db_exec(
                'INSERT INTO subscribers (email, status, general_list, created_at, confirmed_at, ip, consent_text, signup_lists, source, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$email, $double ? 'pending' : 'active', $general ? 1 : 0, $now, $double ? null : $now, $ip, $consent, $snapshot, 'form', $now]
            );
            $id = (int) db()->lastInsertId();
            $result = 'created';
        }
        self::setLists($id, $general, $feedIds);
        if (!$double) {
            self::activate($id); // pending rows from an earlier double opt-in period
        }
        $sub = self::find($id);

        if (!Tokens::allowMailTo($email)) {
            return ['result' => 'throttled', 'mail_ok' => false];
        }
        try {
            if ($double) {
                $raw = Tokens::create('confirm', $email, $id, Tokens::CONFIRM_TTL);
                Mailer::sendConfirmation($sub, nm_abs_url('confirm.php', ['token' => $raw]));
            } else {
                Mailer::sendWelcome($sub);
            }
            $mailOk = true;
        } catch (Throwable $e) {
            nm_log('mail', 'Signup e-mail to ' . $email . ' failed: ' . $e->getMessage());
            $mailOk = false;
        }
        nm_log('subscribers', 'Signup ' . $result . ' #' . $id . ' (' . ($double ? 'double' : 'single') . ' opt-in)');
        return ['result' => $result, 'mail_ok' => $mailOk];
    }

    /**
     * Admin CSV import. $rows: list of e-mail strings. Imported subscribers
     * are active immediately, with an explicit consent note.
     * Returns ['added' => n, 'updated' => n, 'invalid' => [..], 'duplicates' => n].
     */
    public static function import(array $emails, bool $general, array $feedIds, string $note): array
    {
        $feedIds = self::filterFeedIds($feedIds, false);
        $now = nm_now();
        $consent = t('import.consent_text', ['date' => nm_format_date($now), 'admin' => (string) setting('admin_email', '')]);
        if ($note !== '') {
            $consent .= ' — ' . $note;
        }
        $names = nm_describe_lists($general, $feedIds ? array_column(db_all('SELECT name FROM feeds WHERE id IN (' . db_placeholders($feedIds) . ') ORDER BY name', $feedIds), 'name') : []);
        $snapshot = implode(', ', $names);
        $stats = ['added' => 0, 'updated' => 0, 'invalid' => [], 'duplicates' => 0];
        $seen = [];
        db_tx(function (PDO $pdo) use ($emails, $general, $feedIds, $now, $consent, $snapshot, &$stats, &$seen) {
            $ins = $pdo->prepare(
                "INSERT INTO subscribers (email, status, general_list, created_at, confirmed_at, ip, consent_text, signup_lists, source, updated_at)
                 VALUES (?, 'active', ?, ?, ?, '', ?, ?, 'import', ?)"
            );
            $link = $pdo->prepare('INSERT OR IGNORE INTO subscriber_feeds (subscriber_id, feed_id, created_at) VALUES (?, ?, ?)');
            $find = $pdo->prepare('SELECT id, status, general_list FROM subscribers WHERE email = ?');
            $upd = $pdo->prepare('UPDATE subscribers SET general_list = MAX(general_list, ?), updated_at = ? WHERE id = ?');
            foreach ($emails as $line => $raw) {
                $email = nm_normalize_email((string) $raw);
                if ($email === '') {
                    continue;
                }
                if (!nm_valid_email($email)) {
                    $stats['invalid'][] = [$line, (string) $raw];
                    continue;
                }
                if (isset($seen[$email])) {
                    $stats['duplicates']++;
                    continue;
                }
                $seen[$email] = true;
                $find->execute([$email]);
                $row = $find->fetch();
                if ($row) {
                    // Existing address: only add the chosen lists, keep its own consent trail.
                    $upd->execute([$general ? 1 : 0, $now, $row['id']]);
                    $id = (int) $row['id'];
                    $stats['updated']++;
                } else {
                    $ins->execute([$email, $general ? 1 : 0, $now, $now, $consent, $snapshot, $now]);
                    $id = (int) $pdo->lastInsertId();
                    $stats['added']++;
                }
                foreach ($feedIds as $fid) {
                    $link->execute([$id, $fid, $now]);
                }
            }
        });
        nm_log('subscribers', 'Import: ' . $stats['added'] . ' added, ' . $stats['updated'] . ' updated, ' . count($stats['invalid']) . ' invalid');
        return $stats;
    }

    // --- Admin listing -------------------------------------------------------

    /** Builds the WHERE clause for the admin list. */
    private static function where(string $search, string $status, string $list): array
    {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = "s.email LIKE ? ESCAPE '\\'";
            $params[] = '%' . strtr(mb_strtolower($search), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }
        if ($status === 'active' || $status === 'pending') {
            $where[] = 's.status = ?';
            $params[] = $status;
        }
        if ($list === 'general') {
            $where[] = 's.general_list = 1';
        } elseif (preg_match('/^feed:(\d+)$/', $list, $m)) {
            $where[] = 'EXISTS (SELECT 1 FROM subscriber_feeds sf WHERE sf.subscriber_id = s.id AND sf.feed_id = ?)';
            $params[] = (int) $m[1];
        } elseif ($list === 'none') {
            $where[] = 's.general_list = 0 AND NOT EXISTS (SELECT 1 FROM subscriber_feeds sf WHERE sf.subscriber_id = s.id)';
        }
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public static function countFiltered(string $search, string $status, string $list): int
    {
        list($w, $p) = self::where($search, $status, $list);
        return (int) db_value('SELECT COUNT(*) FROM subscribers s ' . $w, $p);
    }

    public static function listFiltered(string $search, string $status, string $list, int $limit, int $offset): array
    {
        list($w, $p) = self::where($search, $status, $list);
        $rows = db_all('SELECT s.* FROM subscribers s ' . $w . ' ORDER BY s.created_at DESC, s.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $p);
        if (!$rows) {
            return [];
        }
        // Attach feed names in one query.
        $ids = array_column($rows, 'id');
        $map = [];
        foreach (db_all('SELECT sf.subscriber_id, f.name FROM subscriber_feeds sf JOIN feeds f ON f.id = sf.feed_id WHERE sf.subscriber_id IN (' . db_placeholders($ids) . ') ORDER BY f.name', $ids) as $r) {
            $map[$r['subscriber_id']][] = $r['name'];
        }
        foreach ($rows as &$r) {
            $r['feed_names'] = isset($map[$r['id']]) ? $map[$r['id']] : [];
        }
        return $rows;
    }

    public static function stats(): array
    {
        return [
            'active' => (int) db_value("SELECT COUNT(*) FROM subscribers WHERE status = 'active'"),
            'pending' => (int) db_value("SELECT COUNT(*) FROM subscribers WHERE status = 'pending'"),
            'general' => (int) db_value("SELECT COUNT(*) FROM subscribers WHERE status = 'active' AND general_list = 1"),
            'last7' => (int) db_value("SELECT COUNT(*) FROM subscribers WHERE status = 'active' AND created_at > ?", [nm_now() - 7 * 86400]),
        ];
    }
}
