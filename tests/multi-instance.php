<?php
/**
 * Two independent instances on the SAME domain, in two sub-folders
 * (/a/ and /b/), each with its own private folder. Checks that nothing leaks
 * from one to the other: admin session, subscriber self-service session,
 * CSRF tokens.
 *   php tests/multi-instance.php
 */
require __DIR__ . '/lib.php';

if (!extension_loaded('curl')) {
    echo "curl extension required for this test\n";
    exit(1);
}

/** Recursive copy (tests only). */
function copy_tree(string $from, string $to): void
{
    @mkdir($to, 0755, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
        $target = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
        if ($f->isDir()) {
            @mkdir($target, 0755, true);
        } else {
            copy($f->getPathname(), $target);
        }
    }
}

$root = dirname(__DIR__);
$tmp = tests_tmpdir('multi');
$docroot = $tmp . '/public_html';
$mailDir = $tmp . '/mails';
mkdir($mailDir);
foreach (['a', 'b'] as $x) {
    copy_tree($root . '/notifyme', $tmp . '/nm-' . $x);
    foreach (['notifyme.sqlite', 'secret.php', 'installed.lock', 'config.local.php'] as $f) {
        @unlink($tmp . '/nm-' . $x . '/data/' . $f);
    }
    @unlink($tmp . '/nm-' . $x . '/config.local.php');
    copy_tree($root . '/www', $docroot . '/' . $x);
    file_put_contents($docroot . '/' . $x . '/notifyme-path.php', "<?php return '" . $tmp . '/nm-' . $x . "';\n");
}

$php = PHP_BINARY;
$smtpPort = tests_free_port();
$webPort = tests_free_port();
$base = 'http://127.0.0.1:' . $webPort . '/';
$smtp = tests_spawn([$php, __DIR__ . '/servers/smtp-sink.php', (string) $smtpPort, $mailDir], $smtpPort);
$env = getenv();
unset($env['NOTIFYME_DATA']);
$web = tests_spawn([$php, '-S', '127.0.0.1:' . $webPort, '-t', $docroot], $webPort);
$jar = $tmp . '/cookies.txt';
register_shutdown_function(function () use ($smtp, $web, $tmp) {
    tests_kill($web);
    tests_kill($smtp);
    tests_rmdir($tmp);
});

function get(string $url, ?array $post = null): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '']);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $r = ['body' => $body, 'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), 'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE)];
    curl_close($ch);
    return $r;
}

function csrf(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function newest_mail(string $dir): string
{
    $files = glob($dir . '/*.eml');
    sort($files);
    return $files ? quoted_printable_decode(file_get_contents(end($files))) : '';
}

test('two instances install side by side, each with its own data', function () use ($base, $smtpPort, $tmp) {
    foreach (['a', 'b'] as $x) {
        $r = get($base . $x . '/install.php?lang=en');
        $r = get($base . $x . '/install.php', [
            'csrf' => csrf($r['body']), 'lang' => 'en', 'site_name' => 'Site ' . strtoupper($x), 'admin_email' => 'admin@example.com',
            'base_url' => $base . $x, 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $smtpPort, 'smtp_encryption' => 'none',
            'smtp_username' => 'u', 'smtp_password' => 'p', 'smtp_verify_cert' => '1', 'from_name' => '', 'from_email' => 'news@example.com', 'send_test' => '0',
        ]);
        check(strpos($r['body'], 'Installation complete') !== false, 'instance ' . $x . ' installed');
        check(is_file($tmp . '/nm-' . $x . '/data/notifyme.sqlite'), 'instance ' . $x . ' has its own database');
    }
});

test('the admin session of one instance does not open the other (same admin e-mail)', function () use ($base, $jar) {
    @unlink($jar);
    // Log in to A only, with the emergency file.
    global $tmp;
    touch($tmp . '/nm-a/data/emergency-login.txt');
    $r = get($base . 'a/admin/login.php');
    $r = get($base . 'a/admin/login.php', ['csrf' => csrf($r['body']), 'action' => 'emergency']);
    check(strpos($r['url'], '/a/admin/index.php') !== false, 'logged in to A');
    $r = get($base . 'b/admin/index.php');
    check(strpos($r['url'], '/b/admin/login.php') !== false, 'B still asks to log in (got ' . $r['url'] . ')');
    $r = get($base . 'a/admin/index.php');
    check(strpos($r['url'], '/a/admin/index.php') !== false, 'A session still valid after visiting B');
});

test('a subscriber logged in on A cannot see the subscriber with the same number on B', function () use ($base, $mailDir, $jar) {
    // Subscriber #1 on each instance, different people.
    foreach (['a' => 'alice@example.com', 'b' => 'bob@example.com'] as $x => $email) {
        @unlink($jar);
        global $tmp;
        touch($tmp . '/nm-' . $x . '/data/emergency-login.txt');
        $r = get($base . $x . '/admin/login.php');
        get($base . $x . '/admin/login.php', ['csrf' => csrf($r['body']), 'action' => 'emergency']);
        $r = get($base . $x . '/admin/import.php');
        get($base . $x . '/admin/import.php', ['csrf' => csrf($r['body']), 'paste' => $email, 'general' => '1', 'note' => 't', 'attest' => '1']);
    }
    @unlink($jar);
    $r = get($base . 'a/manage.php');
    get($base . 'a/manage.php', ['csrf' => csrf($r['body']), 'action' => 'request', 'email' => 'alice@example.com']);
    preg_match('~manage\.php\?token=([A-Za-z0-9_-]+)~', newest_mail($mailDir), $m);
    $r = get($base . 'a/manage.php', ['action' => 'login', 'token' => $m[1]]);
    check(strpos($r['body'], 'alice@example.com') !== false, 'Alice sees her data on A');
    $r = get($base . 'b/manage.php');
    check(strpos($r['body'], 'bob@example.com') === false, "Bob's data is not shown to Alice on B");
    check(strpos($r['body'], 'name="action" value="request"') !== false, 'B asks for an e-mail address');
});

test('session cookies are named per instance and limited to its folder', function () use ($jar) {
    $cookies = file_get_contents($jar);
    preg_match_all('/^\S+\t\S+\t(\S+)\t\S+\t\S+\t(nm_\w+)\t/m', $cookies, $m, PREG_SET_ORDER);
    $byPath = [];
    foreach ($m as $c) {
        $byPath[$c[1]] = $c[2];
    }
    check(isset($byPath['/a/']) && isset($byPath['/b/']), 'cookie paths /a/ and /b/ (got ' . json_encode($byPath) . ')');
    check(isset($byPath['/a/'], $byPath['/b/']) && $byPath['/a/'] !== $byPath['/b/'], 'different cookie names');
});

exit(tests_summary());
