<?php
/**
 * End-to-end web test: runs the real pages under PHP's built-in web server,
 * from the installer to unsubscribing, and checks every page for PHP
 * warnings/notices in both languages.
 *   php tests/http.php
 */
require __DIR__ . '/lib.php';

if (!extension_loaded('curl')) {
    echo "curl extension required for this test\n";
    exit(1);
}
$root = dirname(__DIR__);
$dataDir = tests_tmpdir('http');
$mailDir = tests_tmpdir('httpmails');
$cookieJar = $dataDir . '-cookies.txt';
$php = PHP_BINARY;
$smtpPort = tests_free_port();
$webPort = tests_free_port();
$base = 'http://127.0.0.1:' . $webPort . '/';
$smtp = tests_spawn([$php, __DIR__ . '/servers/smtp-sink.php', (string) $smtpPort, $mailDir], $smtpPort);
$web = tests_spawn([$php, '-S', '127.0.0.1:' . $webPort, '-t', $root . '/www'], $webPort, ['NOTIFYME_DATA' => $dataDir]);
register_shutdown_function(function () use ($smtp, $web, $dataDir, $mailDir, $cookieJar) {
    tests_kill($web);
    tests_kill($smtp);
    tests_rmdir($dataDir);
    tests_rmdir($mailDir);
    @unlink($cookieJar);
});

/** HTTP request with a cookie jar. Returns ['status', 'body', 'url']. */
function http(string $url, ?array $post = null, ?string $jar = null): array
{
    global $cookieJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar ?: $cookieJar,
        CURLOPT_COOKIEFILE => $jar ?: $cookieJar,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_PROXY => '',
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $r = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body, 'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
    curl_close($ch);
    return $r;
}

function csrf_of(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function no_php_errors(array $r, string $what): void
{
    check_same(200, $r['status'], $what . ' status');
    if (preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)\b[^<]{0,160}/', $r['body'], $m)) {
        check(false, $what . ': ' . $m[0]);
    }
}

function last_mail(string $dir): string
{
    $files = glob($dir . '/*.eml');
    sort($files);
    return $files ? quoted_printable_decode(file_get_contents(end($files))) : '';
}

$state = [];

test('installer (English) creates the application and logs the admin in', function () use ($base, $smtpPort, $mailDir, &$state) {
    $r = http($base . 'install.php?lang=en');
    no_php_errors($r, 'install form');
    check(strpos($r['body'], 'Server check') !== false, 'English wizard');
    $r = http($base . 'install.php', [
        'csrf' => csrf_of($r['body']), 'lang' => 'en', 'site_name' => 'HTTP Test', 'admin_email' => 'admin@example.com',
        'base_url' => rtrim($base, '/'), 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $smtpPort, 'smtp_encryption' => 'none',
        'smtp_username' => 'u', 'smtp_password' => 'p', 'smtp_verify_cert' => '1', 'from_name' => '', 'from_email' => 'news@example.com', 'send_test' => '1',
    ]);
    no_php_errors($r, 'install result');
    check(strpos($r['body'], 'Installation complete') !== false, 'installed');
    check(strpos(last_mail($mailDir), 'the SMTP configuration works') !== false, 'test e-mail received');
    check(strpos(http($base . 'install.php')['body'], 'Already installed') !== false, 'installer locked');
});

test('every admin page renders without PHP warnings (en and fr)', function () use ($base) {
    $pages = ['admin/index.php', 'admin/send.php', 'admin/campaigns.php', 'admin/feeds.php', 'admin/import.php', 'admin/export.php',
        'admin/settings.php', 'admin/smtp.php', 'admin/cron.php', 'admin/bounces.php', 'admin/index.php?status=bounced', ''];
    foreach ($pages as $p) {
        no_php_errors(http($base . $p), 'en ' . $p);
    }
    $r = http($base . 'admin/settings.php');
    preg_match_all('/<(?:input|textarea)[^>]*name="(\w+)"[^>]*?(?:value="([^"]*)"[^>]*)?>(?:([^<]*)<\/textarea>)?/', $r['body'], $m, PREG_SET_ORDER);
    $form = [];
    foreach ($m as $f) {
        $form[$f[1]] = html_entity_decode(isset($f[3]) && $f[3] !== '' ? $f[3] : (isset($f[2]) ? $f[2] : ''), ENT_QUOTES, 'UTF-8');
    }
    unset($form['unsubscribe_confirm']);
    $form['language'] = 'fr';
    $form['optin_mode'] = 'single';
    $r = http($base . 'admin/settings.php', $form);
    check(strpos($r['body'], '<html lang="fr">') !== false && strpos($r['body'], 'alert-success') !== false, 'switched to French');
    foreach ($pages as $p) {
        no_php_errors(http($base . $p), 'fr ' . $p);
    }
});

test('public signup, campaign sent through the AJAX endpoint, unsubscribe link', function () use ($base, $mailDir) {
    $pub = sys_get_temp_dir() . '/nm-http-visitor-' . getmypid();
    $r = http($base, null, $pub);
    no_php_errors($r, 'signup form');
    preg_match('/name="stamp" value="([^"]+)"/', $r['body'], $s);
    sleep(2); // the form refuses instant (bot) submissions
    $r = http($base, ['csrf' => csrf_of($r['body']), 'stamp' => $s[1], 'email' => 'visitor@example.com', 'consent' => '1', 'website' => ''], $pub);
    check(strpos($r['body'], 'alert-success') !== false, 'signup accepted');
    check(strpos(last_mail($mailDir), 'visitor@example.com') !== false, 'welcome e-mail');

    $r = http($base . 'admin/send.php');
    $r = http($base . 'admin/send.php', ['csrf' => csrf_of($r['body']), 'audience' => 'general', 'subject' => 'Bonjour', 'format' => 'text', 'body' => 'Hello https://example.com', 'action' => 'send']);
    check(preg_match('/data-campaign="(\d+)"/', $r['body'], $c) === 1, 'campaign page');
    preg_match('/name="csrf-token" content="([^"]+)"/', $r['body'], $t);
    $j = json_decode(http($base . 'admin/api.php', ['action' => 'process', 'campaign' => $c[1], 'csrf' => $t[1]])['body'], true);
    check_same(1, $j['result']['sent']);
    check_same(0, $j['progress']['pending']);
    check_same(401, http($base . 'admin/api.php', ['action' => 'process'], $pub)['status'], 'API refuses visitors');

    preg_match('~(http://[^\s]+unsubscribe\.php\?t=[0-9]+-[A-Za-z0-9_-]+)~', last_mail($mailDir), $u);
    $r = http($u[1], null, $pub);
    no_php_errors($r, 'unsubscribe');
    check(strpos($r['body'], 'visitor@example.com') !== false && strpos($r['body'], 'alert-success') !== false, 'unsubscribed');
    @unlink($pub);
});

test('scheduling a message from the composer, then sending it now', function () use ($base, $mailDir) {
    $r = http($base . 'admin/import.php');
    http($base . 'admin/import.php', ['csrf' => csrf_of($r['body']), 'paste' => 'scheduled@example.com', 'general' => '1', 'note' => 'test', 'attest' => '1']);
    $r = http($base . 'admin/send.php');
    $r = http($base . 'admin/send.php', ['csrf' => csrf_of($r['body']), 'audience' => 'all', 'subject' => 'Plus tard', 'format' => 'text',
        'body' => 'Later', 'when' => 'later', 'send_at' => date('Y-m-d\\TH:i', time() + 86400), 'action' => 'send']);
    no_php_errors($r, 'scheduled campaign page');
    check(strpos($r['body'], 'class="badge badge-sending"') !== false && strpos($r['body'], 'id="campaign"') === false, 'shown as scheduled, no progress bar');
    preg_match('/name="id" value="(\d+)"/', $r['body'], $id);
    $r = http($base . 'admin/send.php');
    $bad = http($base . 'admin/send.php', ['csrf' => csrf_of($r['body']), 'audience' => 'all', 'subject' => 'x', 'format' => 'text', 'body' => 'x', 'when' => 'later', 'send_at' => '2001-01-01T10:00', 'action' => 'send']);
    check(strpos($bad['body'], 'alert-error') !== false, 'past date refused');
    $r = http($base . 'admin/campaigns.php?id=' . $id[1]);
    $r = http($base . 'admin/campaigns.php', ['csrf' => csrf_of($r['body']), 'id' => $id[1], 'action' => 'send_now']);
    check(strpos($r['body'], 'id="campaign"') !== false, 'now sending');
    preg_match('/name="csrf-token" content="([^"]+)"/', $r['body'], $t);
    $j = json_decode(http($base . 'admin/api.php', ['action' => 'process', 'campaign' => $id[1], 'csrf' => $t[1]])['body'], true);
    check_same(1, $j['result']['sent'], 'sent');
    check(strpos(last_mail($mailDir), 'scheduled@example.com') !== false, 'received');
});

test('self-service by magic link', function () use ($base, $mailDir) {
    $jar = sys_get_temp_dir() . '/nm-http-self-' . getmypid();
    $r = http($base . 'admin/import.php');
    http($base . 'admin/import.php', ['csrf' => csrf_of($r['body']), 'paste' => 'self@example.com', 'general' => '1', 'note' => 'test', 'attest' => '1']);
    $r = http($base . 'manage.php', null, $jar);
    http($base . 'manage.php', ['csrf' => csrf_of($r['body']), 'action' => 'request', 'email' => 'self@example.com'], $jar);
    check(preg_match('~manage\.php\?token=([A-Za-z0-9_-]+)~', last_mail($mailDir), $m) === 1, 'magic link e-mailed');
    $r = http($base . 'manage.php', ['action' => 'login', 'token' => $m[1]], $jar);
    no_php_errors($r, 'manage page');
    check(strpos($r['body'], 'self@example.com') !== false && strpos($r['body'], 'name="feeds[]"') === false, 'own data shown');
    check(strpos(http($base . 'manage.php', ['action' => 'login', 'token' => $m[1]], sys_get_temp_dir() . '/nm-other-' . getmypid())['body'], 'alert-error') !== false, 'link works once');
    @unlink($jar);
    @unlink(sys_get_temp_dir() . '/nm-other-' . getmypid());
});

exit(tests_summary());
