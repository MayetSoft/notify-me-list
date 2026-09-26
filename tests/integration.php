<?php
/**
 * Integration tests: real SMTP, IMAP and HTTP round-trips against local test
 * servers (tests/servers/), using the library directly.
 *   php tests/integration.php
 */
require __DIR__ . '/lib.php';

$dataDir = tests_tmpdir('integ');
$mailDir = tests_tmpdir('mails');
$feedDir = tests_tmpdir('feeds');
$imapDir = tests_tmpdir('imap');
putenv('NOTIFYME_DATA=' . $dataDir);
define('NM_ALLOW_PRIVATE_FEEDS', true); // local feed server on 127.0.0.1
require dirname(__DIR__) . '/notifyme/bootstrap.php';

$php = PHP_BINARY;
$smtpPort = tests_free_port();
$imapPort = tests_free_port();
$feedPort = tests_free_port();
$smtp = tests_spawn([$php, __DIR__ . '/servers/smtp-sink.php', (string) $smtpPort, $mailDir], $smtpPort);
$feeds = tests_spawn([$php, '-S', '127.0.0.1:' . $feedPort, '-t', $feedDir], $feedPort);
$procs = [$smtp, $feeds];
register_shutdown_function(function () use (&$procs, $dataDir, $mailDir, $feedDir, $imapDir) {
    foreach ($procs as $p) {
        tests_kill($p);
    }
    foreach ([$dataDir, $mailDir, $feedDir, $imapDir] as $d) {
        tests_rmdir($d);
    }
});

/** Sent messages, oldest first: [['to' => ..., 'from' => envelope sender, 'raw' => ...]]; $clear empties the sink. */
function sent_mails(string $dir, bool $clear = true): array
{
    $out = [];
    foreach (glob($dir . '/*.eml') as $f) {
        $raw = file_get_contents($f);
        preg_match('/^X-Envelope: MAIL FROM:<([^>]*)> RCPT TO:<([^>]*)>/', $raw, $m);
        $out[] = ['from' => $m[1], 'to' => $m[2], 'raw' => $raw, 'text' => quoted_printable_decode($raw)];
        if ($clear) {
            unlink($f);
        }
    }
    return $out;
}

// --- programmatic installation ---------------------------------------------
@mkdir(NM_LOG_DIR);
Crypto::generateSecretFile();
db_migrate();
foreach ([
    'site_name' => 'Test Blog', 'admin_email' => 'admin@example.com', 'base_url' => 'http://127.0.0.1:1',
    'language' => 'en', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $smtpPort, 'smtp_encryption' => 'none',
    'smtp_username' => 'user', 'smtp_password' => Crypto::encrypt('secret'), 'from_email' => 'news@example.com',
    'send_delay_ms' => '0', 'max_per_hour' => '0',
] as $k => $v) {
    setting_set($k, $v);
}
file_put_contents(NM_LOCK_FILE, 'test');

echo "Schema\n";

test('fresh install is at the latest schema version, upgrades are idempotent', function () {
    check_same(NM_SCHEMA_VERSION, db_schema_version());
    db_migrate();
    db_upgrade_if_needed();
    check_same(NM_SCHEMA_VERSION, db_schema_version());
    $cols = array_column(db_all('PRAGMA table_info(subscribers)'), 'name');
    check(in_array('bounce_hard', $cols, true), 'bounce columns added');
});

echo "\nSignup and sending\n";

test('single opt-in signup sends a welcome e-mail with unsubscribe headers', function () use ($mailDir) {
    $r = Subscribers::signup('Alice@Example.com', true, [], '127.0.0.1');
    check_same('created', $r['result']);
    check($r['mail_ok'], 'mail sent');
    $m = sent_mails($mailDir);
    check_same(1, count($m));
    check_same('alice@example.com', $m[0]['to']);
    check(strpos($m[0]['raw'], 'List-Unsubscribe: <http://127.0.0.1:1/unsubscribe.php?t=') !== false, 'List-Unsubscribe header');
    check(strpos($m[0]['raw'], 'List-Unsubscribe-Post: List-Unsubscribe=One-Click') !== false, 'one-click header');
});

test('double opt-in: pending until confirmed', function () use ($mailDir) {
    setting_set('optin_mode', 'double');
    Subscribers::signup('bob@example.com', true, [], '127.0.0.1');
    check_same('pending', Subscribers::findByEmail('bob@example.com')['status']);
    $m = sent_mails($mailDir);
    check(preg_match('~confirm\.php\?token=([A-Za-z0-9_-]+)~', $m[0]['text'], $t) === 1, 'confirmation link');
    $row = Tokens::consume($t[1], 'confirm');
    check($row !== null, 'token valid once');
    check_same(null, Tokens::consume($t[1], 'confirm'), 'token single use');
    Subscribers::activate((int) $row['subscriber_id']);
    check_same('active', Subscribers::findByEmail('bob@example.com')['status']);
    setting_set('optin_mode', 'single');
});

test('manual campaign: one individual e-mail per subscriber, each with its own unsubscribe link', function () use ($mailDir) {
    Subscribers::import(['carol@example.com', 'dave@example.com'], true, [], 'test');
    $cid = Queue::createManual('Hello', '<p>Hi</p>', 'Hi', 'general');
    $r = Queue::process(100, 30, $cid);
    check_same(4, $r['sent']);
    $m = sent_mails($mailDir);
    $links = [];
    foreach ($m as $x) {
        preg_match('~unsubscribe\.php\?t=([0-9]+-[A-Za-z0-9_-]+)~', $x['text'], $u);
        $links[$x['to']] = $u[1];
    }
    check_same(4, count(array_unique($links)), 'unique links');
    $sub = Tokens::subscriberFromUnsubscribeToken($links['dave@example.com']);
    check($sub !== null && $sub['email'] === 'dave@example.com', 'link identifies the subscriber');
    check_same(null, Tokens::subscriberFromUnsubscribeToken(substr($links['dave@example.com'], 0, -2) . 'xx'), 'forged link refused');
});

echo "\nFeeds\n";

test('feed: baseline sends nothing, new items give one grouped e-mail per subscriber', function () use ($feedDir, $mailDir) {
    global $feedPort;
    $fx = __DIR__ . '/fixtures/feeds';
    copy($fx . '/rss2.xml', $feedDir . '/rss.xml');
    copy($fx . '/atom.xml', $feedDir . '/atom.xml');
    $a = Feeds::create('Blog', 'http://127.0.0.1:' . $feedPort . '/rss.xml', 15, false);
    $b = Feeds::create('News', 'http://127.0.0.1:' . $feedPort . '/atom.xml', 15, false);
    check_same(3, $a['items']);
    $both = Subscribers::findByEmail('carol@example.com');
    Subscribers::setLists((int) $both['id'], true, [$a['id'], $b['id']]);
    $one = Subscribers::findByEmail('dave@example.com');
    Subscribers::setLists((int) $one['id'], true, [$a['id']]);
    check_same(0, Feeds::checkDue(true)['new_items'], 'nothing new yet');

    file_put_contents($feedDir . '/rss.xml', str_replace('<item><title>Deuxième', '<item><title>Troisième</title><link>https://example.com/3</link><guid>post-3</guid></item><item><title>Deuxième', file_get_contents($feedDir . '/rss.xml')));
    file_put_contents($feedDir . '/atom.xml', str_replace('<entry>', '<entry><title>Entrée C</title><link href="https://example.org/c"/><id>urn:c</id></entry><entry>', file_get_contents($feedDir . '/atom.xml')));
    $report = Feeds::checkDue(true);
    check_same(2, $report['new_items']);
    Queue::process(100, 30);
    $m = sent_mails($mailDir);
    $to = array_column($m, 'to');
    sort($to);
    check_same(['carol@example.com', 'dave@example.com'], $to, 'only feed subscribers, one e-mail each');
    foreach ($m as $x) {
        $hasC = strpos($x['text'], 'Entrée C') !== false;
        check($x['to'] === 'carol@example.com' ? $hasC : !$hasC, 'grouping for ' . $x['to']);
        check(strpos($x['text'], 'Troisième') !== false, 'blog item for ' . $x['to']);
    }
    check_same(0, Feeds::checkDue(true)['new_items'], 'never sent twice');
});

echo "\nRate limits\n";

test('host quota refusal pauses sending and keeps e-mails queued', function () use ($mailDir) {
    global $smtp, $smtpPort, $procs, $php;
    tests_kill($smtp);
    $smtp = tests_spawn([$php, __DIR__ . '/servers/smtp-sink.php', (string) $smtpPort, $mailDir, '1'], $smtpPort);
    $procs[] = $smtp;
    Mailer::closeConnection();
    $cid = Queue::createManual('Quota', '<p>x</p>', 'x', 'general');
    $r = Queue::process(100, 30, $cid);
    check_same(1, $r['sent']);
    check_same(0, $r['failed'], 'nobody marked failed');
    check($r['throttled'] && $r['host_limit'] !== '', 'host limit detected');
    check(Queue::pausedUntil() > time(), 'paused');
    check_same(0, Queue::process(100, 30, $cid)['sent'], 'nothing sent while paused');
    Queue::clearPause();
    tests_kill($smtp);
    $smtp = tests_spawn([$php, __DIR__ . '/servers/smtp-sink.php', (string) $smtpPort, $mailDir], $smtpPort);
    $procs[] = $smtp;
    Mailer::closeConnection();
    $r = Queue::process(100, 30, $cid);
    check_same(3, $r['sent'], 'the rest goes out after the pause');
    sent_mails($mailDir);
});

test('hourly and daily caps stop before contacting the server', function () {
    $cid = Queue::createManual('Caps', '<p>x</p>', 'x', 'general');
    setting_set('max_per_hour', '1');
    db_exec('INSERT INTO send_log (sent_at) VALUES (?)', [time()]);
    $r = Queue::process(100, 30, $cid);
    check(0 === $r['sent'] && $r['throttled'], 'hourly cap');
    setting_set('max_per_hour', '0');
    setting_set('max_per_day', '1');
    $r = Queue::process(100, 30, $cid);
    check(0 === $r['sent'] && $r['throttled'], 'daily cap');
    setting_set('max_per_day', '0');
    Queue::setStatus($cid, 'cancelled');
});

echo "\nBounces\n";

test('a permanent SMTP refusal at send time deactivates the address', function () use ($mailDir) {
    Subscribers::import(['reject-me@example.com'], true, [], 'test');
    $cid = Queue::createManual('Hard', '<p>x</p>', 'x', 'general');
    Queue::process(100, 30, $cid);
    $s = Subscribers::findByEmail('reject-me@example.com');
    check_same('bounced', $s['status']);
    check_same(1, (int) $s['bounce_hard']);
    check_same('smtp', db_value("SELECT source FROM bounce_log WHERE email = 'reject-me@example.com'"));
    sent_mails($mailDir);
});

test('bounce mailbox over IMAP: bounces processed, other mail untouched, nothing read twice', function () use ($imapDir) {
    global $imapPort, $procs, $php;
    foreach (['dsn-hard', 'dsn-multi', 'exim-failed', 'outlook-base64', 'delay', 'out-of-office', 'normal'] as $i => $f) {
        copy(__DIR__ . '/fixtures/bounces/' . $f . '.eml', $imapDir . '/' . $i . '-' . $f . '.eml');
    }
    Subscribers::import(['gone@example.com', 'dead@example.org', 'full@example.net', 'nobody@example.com', 'slow@example.com'], true, [], 'test');
    $state = $imapDir . '/state.json';
    $imap = tests_spawn([$php, __DIR__ . '/servers/fake-imap.php', (string) $imapPort, $imapDir, $state], $imapPort);
    $procs[] = $imap;
    foreach (['bounce_enabled' => '1', 'bounce_same_as_smtp' => '0', 'imap_host' => '127.0.0.1', 'imap_port' => (string) $imapPort,
        'imap_encryption' => 'none', 'imap_username' => 'user', 'imap_password' => Crypto::encrypt('pa ss"w0rd')] as $k => $v) {
        setting_set($k, $v);
    }
    $r = Bounces::processMailbox();
    check_same(7, $r['checked']);
    check_same(4, $r['bounces'], 'dsn-hard, dsn-multi, exim, outlook');
    foreach (['gone@example.com' => 'bounced', 'dead@example.org' => 'bounced', 'nobody@example.com' => 'bounced', 'full@example.net' => 'active', 'slow@example.com' => 'active'] as $email => $status) {
        check_same($status, Subscribers::findByEmail($email)['status'], $email);
    }
    check_same(1, (int) Subscribers::findByEmail('full@example.net')['bounce_soft'], 'soft bounce counted');
    $flags = json_decode(file_get_contents($state), true);
    check_same(['\\Seen'], $flags['0-dsn-hard.eml'], 'bounce marked as read');
    check_same([], $flags['6-normal.eml'], 'normal mail untouched');
    check_same([], $flags['5-out-of-office.eml'], 'out-of-office untouched');
    check_same(0, Bounces::processMailbox()['checked'], 'second run reads nothing old');
    tests_kill($imap);
});

test('IMAP: non-ASCII password (literal), wrong password, delete action', function () use ($imapDir) {
    global $imapPort, $procs, $php;
    $state = $imapDir . '/state.json';
    $imap = tests_spawn([$php, __DIR__ . '/servers/fake-imap.php', (string) $imapPort, $imapDir, $state], $imapPort);
    $procs[] = $imap;
    $cfg = Bounces::imapConfig();
    $cfg['username'] = 'user2';
    $cfg['password'] = 'pässwörd';
    $t = [];
    check_same(7, Bounces::testConnection($cfg, $t)['exists'], 'literal login works');
    $cfg['password'] = 'wrong';
    $threw = false;
    try {
        Bounces::testConnection($cfg, $t);
    } catch (ImapException $e) {
        $threw = strpos($e->getMessage(), 'AUTHENTICATIONFAILED') !== false;
    }
    check($threw, 'bad password reported');
    check(strpos(implode("\n", $t), 'wörd') === false && strpos(implode("\n", $t), 'wrong') === false, 'password not in transcript');
    setting_set('bounce_action', 'delete');
    setting_set('bounce_last_uid', '0');
    setting_set('bounce_uidvalidity', '0');
    db_exec("UPDATE subscribers SET status = 'active' WHERE email = 'gone@example.com'");
    Bounces::processMailbox();
    $flags = json_decode(file_get_contents($state), true);
    check(!isset($flags['0-dsn-hard.eml']), 'bounce deleted');
    check(isset($flags['6-normal.eml']), 'normal mail kept');
    tests_kill($imap);
});

test('a deactivated address can sign up again', function () use ($mailDir) {
    Subscribers::signup('gone@example.com', true, [], '127.0.0.1');
    $s = Subscribers::findByEmail('gone@example.com');
    check_same('active', $s['status']);
    check_same(0, (int) $s['bounce_hard']);
    sent_mails($mailDir);
});

exit(tests_summary());
