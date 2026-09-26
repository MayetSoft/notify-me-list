<?php
/**
 * Unit tests: pure functions and parsers, no network, no web server.
 *   php tests/unit.php
 */
require __DIR__ . '/lib.php';

$dataDir = tests_tmpdir('unit');
putenv('NOTIFYME_DATA=' . $dataDir);
require dirname(__DIR__) . '/notifyme/bootstrap.php';
Crypto::generateSecretFile();
$fx = __DIR__ . '/fixtures';

echo "Bounce parser\n";

test('standard DSN, hard bounce', function () use ($fx) {
    $r = BounceParser::parse(file_get_contents($fx . '/bounces/dsn-hard.eml'));
    check($r !== null, 'recognised as a bounce');
    check_same(1, count($r['recipients']));
    check_same('gone@example.com', $r['recipients'][0]['email']);
    check_same('hard', $r['recipients'][0]['kind']);
    check_same('5.1.1', $r['recipients'][0]['status']);
    check(strpos($r['recipients'][0]['reason'], 'does not exist') !== false, 'folded Diagnostic-Code is kept');
});

test('DSN with several recipients: mailbox full and spam refusal are soft, delayed is ignored', function () use ($fx) {
    $r = BounceParser::parse(file_get_contents($fx . '/bounces/dsn-multi.eml'));
    $by = [];
    foreach ($r['recipients'] as $x) {
        $by[$x['email']] = $x['kind'];
    }
    check_same(['full@example.net' => 'soft', 'spam@example.net' => 'soft'], $by);
});

test('cPanel/Exim failure with X-Failed-Recipients', function () use ($fx) {
    $r = BounceParser::parse(file_get_contents($fx . '/bounces/exim-failed.eml'));
    check_same('dead@example.org', $r['recipients'][0]['email']);
    check_same('hard', $r['recipients'][0]['kind']);
    check_same('5.1.1', $r['recipients'][0]['status']);
});

test('non-standard bounce with a base64 body', function () use ($fx) {
    $r = BounceParser::parse(file_get_contents($fx . '/bounces/outlook-base64.eml'));
    $emails = array_column($r['recipients'], 'email');
    check(in_array('nobody@example.com', $emails, true), 'address found in the decoded body');
    check_same('hard', $r['recipients'][0]['kind']);
    check(!empty($r['recipients'][0]['guessed']), 'marked as guessed');
});

test('delay warnings, out-of-office and normal replies are not bounces', function () use ($fx) {
    foreach (['delay', 'out-of-office', 'normal'] as $f) {
        check_same(null, BounceParser::parse(file_get_contents($fx . '/bounces/' . $f . '.eml')), $f);
    }
});

test('classification of status codes', function () {
    check_same('hard', BounceParser::classify('5.1.1', ''));
    check_same('hard', BounceParser::classify('5.4.4', ''));
    check_same('soft', BounceParser::classify('5.2.2', ''));
    check_same('soft', BounceParser::classify('4.2.2', 'user unknown'));
    check_same('soft', BounceParser::classify('5.7.1', 'blocked by policy'));
    check_same('hard', BounceParser::classify('5.0.0', '550 No such user here'));
    check_same('soft', BounceParser::classify('', 'mailbox is full'));
});

echo "\nSMTP\n";

test('host quota refusals are recognised as rate limits', function () {
    $yes = [
        '550 Domain example.com has exceeded the max emails per hour (100/100 (100%)) allowed.  Message discarded.',
        '451 4.7.1 Rate limit exceeded, try later',
        '421 Too many messages from this account',
        '554 5.7.0 Sending quota exceeded',
    ];
    foreach ($yes as $m) {
        check((new SmtpException($m, false, (int) substr($m, 0, 3)))->isRateLimit(), $m);
    }
    foreach (['550 5.1.1 User unknown', '451 Temporary local problem - please try again later', '554 Message rejected as spam'] as $m) {
        check(!(new SmtpException($m, false, (int) substr($m, 0, 3)))->isRateLimit(), $m);
    }
});

test('MIME message: CRLF bodies, encoded subject, no header injection', function () {
    $cfg = ['from_email' => 'news@example.com', 'from_name' => 'Blog "été"', 'reply_to' => ''];
    $msg = Mailer::build($cfg, 'a@example.com', "Sujet é\r\nBcc: evil@example.com", '<p>Hé</p>', "Ligne 1\nLigne 2", ['X-Test' => "v\r\nBcc: x@y.z"]);
    check(strpos($msg, "\r\nBcc:") === false, 'no injected Bcc header');
    check(preg_match('/^Subject: [^\r\n]*=\?UTF-8\?B\?/m', $msg) === 1, 'UTF-8 subject encoded');
    check(strpos($msg, '=0A') === false, 'line breaks are real CRLF, not =0A');
    check(strpos($msg, "Ligne 1\r\nLigne 2") !== false, 'text part keeps its lines');
});

echo "\nFeeds\n";

test('RSS 2.0', function () use ($fx) {
    $f = FeedParser::parse(file_get_contents($fx . '/feeds/rss2.xml'), 'https://example.com/feed.xml');
    check_same('rss', $f['format']);
    check_same(3, count($f['items']));
    check_same('id:post-2', $f['items'][0]['key']);
    check(strpos($f['items'][0]['summary'], 'alert') === false && strpos($f['items'][0]['summary'], '<') === false, 'summary is plain text without script');
    check_same('https://example.com/relative/1', $f['items'][1]['link'], 'relative link resolved');
    check_same(strtotime('2026-09-01T10:00:00+02:00'), $f['items'][1]['published_at'], 'dc:date parsed');
    check_same('', $f['items'][2]['link'], 'javascript: link dropped');
    check(strpos($f['items'][2]['key'], 'hash:') === 0, 'no guid nor link: hash key');
});

test('Atom', function () use ($fx) {
    $f = FeedParser::parse(file_get_contents($fx . '/feeds/atom.xml'), 'https://example.org/feed');
    check_same('atom', $f['format']);
    check_same('Entrée <A>', $f['items'][0]['title']);
    check_same('https://example.org/a', $f['items'][0]['link'], 'rel=alternate preferred over self');
    check_same('https://example.org/b', $f['items'][1]['link']);
    check_same('Contenu B', $f['items'][1]['summary']);
});

test('RSS 1.0 (RDF)', function () use ($fx) {
    $f = FeedParser::parse(file_get_contents($fx . '/feeds/rdf.xml'));
    check_same('rdf', $f['format']);
    check_same('RDF item', $f['items'][0]['title']);
});

test('broken feed (BOM, bare &, control characters) is recovered', function () use ($fx) {
    $f = FeedParser::parse(file_get_contents($fx . '/feeds/broken.xml'));
    check_same(1, count($f['items']));
    check_same('id:tj', $f['items'][0]['key']);
});

test('entity declarations and non-feeds are refused', function () use ($fx) {
    foreach (['entities.xml', 'not-a-feed.html'] as $file) {
        $threw = false;
        try {
            FeedParser::parse(file_get_contents($fx . '/feeds/' . $file));
        } catch (RuntimeException $e) {
            $threw = true;
        }
        check($threw, $file);
    }
});

test('SSRF: private and special addresses are blocked', function () {
    foreach (['127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', '64:ff9b::a00:1', '2002:7f00:1::1'] as $ip) {
        check(!FeedFetcher::isPublicIp($ip), $ip);
    }
    foreach (['8.8.8.8', '93.184.216.34', '2606:4700:4700::1111'] as $ip) {
        check(FeedFetcher::isPublicIp($ip), $ip);
    }
    foreach (['file:///etc/passwd', 'gopher://x/', 'http://u:p@example.com/', 'http://example.com:8080/'] as $url) {
        $threw = false;
        try {
            FeedFetcher::validateUrl($url);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        check($threw, $url);
    }
    check_same('https://a.com/x/y?z=1', FeedFetcher::resolveRelative('https://a.com/blog/feed.xml', '../x/y?z=1'));
});

echo "\nSecurity helpers\n";

test('encryption at rest round-trips and detects tampering', function () {
    $c = Crypto::encrypt('s3cr3t pässword');
    check($c !== 's3cr3t pässword' && strpos($c, 'v1:') === 0);
    check_same('s3cr3t pässword', Crypto::decrypt($c));
    $raw = base64_decode(substr($c, 3));
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    $threw = false;
    try {
        Crypto::decrypt('v1:' . base64_encode($raw));
    } catch (RuntimeException $e) {
        $threw = true;
    }
    check($threw, 'tampered ciphertext refused');
});

test('text/HTML conversions escape and keep links', function () {
    $h = nm_text_to_html("<b>x</b>\n\nhttps://example.com/a?b=1&c=2.");
    check(strpos($h, '&lt;b&gt;') !== false, 'escaped');
    check(strpos($h, '<a href="https://example.com/a?b=1&amp;c=2">') !== false, 'link, trailing dot excluded');
    check_same("Title\n\nHello World (https://example.com)", nm_html_to_text('<h2>Title</h2><p>Hello <a href="https://example.com">World</a></p>'));
});

test('cron lines are recognised by script path', function () {
    check_same('feeds', CronSetup::ownLine('*/15 * * * * /usr/bin/php ' . NM_ROOT . '/cron/check-feeds.php >/dev/null 2>&1'));
    check_same(null, CronSetup::ownLine('# */15 * * * * php ' . NM_ROOT . '/cron/check-feeds.php'));
    check_same(null, CronSetup::ownLine('0 3 * * * /home/u/backup.sh'));
});

echo "\nTranslations\n";

test('fr and en have the same keys and placeholders', function () {
    $fr = require NM_ROOT . '/lang/fr.php';
    $en = require NM_ROOT . '/lang/en.php';
    check_same([], array_keys(array_diff_key($fr, $en)), 'missing in en');
    check_same([], array_keys(array_diff_key($en, $fr)), 'missing in fr');
    foreach ($fr as $k => $v) {
        preg_match_all('/\{\w+\}/', $v, $a);
        preg_match_all('/\{\w+\}/', isset($en[$k]) ? $en[$k] : '', $b);
        $a = array_unique($a[0]);
        $b = array_unique($b[0]);
        sort($a);
        sort($b);
        if ($a !== $b) {
            check(false, 'placeholders differ for ' . $k);
        }
    }
});

test('every key used in the code exists', function () {
    $fr = require NM_ROOT . '/lang/fr.php';
    $root = dirname(__DIR__);
    $missing = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        $path = $f->getPathname();
        if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false || strpos($path, '/lang/') !== false) {
            continue;
        }
        preg_match_all("/\\bt\\('([a-z0-9_.]+)'/", file_get_contents($path), $m);
        foreach ($m[1] as $key) {
            if (substr($key, -1) !== '_' && substr($key, -1) !== '.' && !isset($fr[$key])) {
                $missing[] = $key . ' (' . basename($path) . ')';
            }
        }
    }
    check_same([], array_values(array_unique($missing)));
});

tests_rmdir($dataDir);
exit(tests_summary());
