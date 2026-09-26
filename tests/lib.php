<?php
/**
 * Minimal test harness (no PHPUnit, no Composer: runs on any PHP 7.4+).
 *   test('name', function () { check(1 + 1 === 2, 'maths'); });
 *   exit(tests_summary());
 */
$GLOBALS['nm_tests'] = ['passed' => 0, 'failed' => 0, 'current' => '', 'failures' => []];

// Any PHP warning, notice or deprecation (not silenced with @) fails the test run.
error_reporting(E_ALL);
set_error_handler(function ($no, $message, $file, $line) {
    if (!(error_reporting() & $no)) {
        return false; // silenced with @
    }
    fail_test('PHP error: ' . $message . ' @ ' . basename($file) . ':' . $line);
    return true;
});

function test(string $name, callable $fn): void
{
    $GLOBALS['nm_tests']['current'] = $name;
    $before = $GLOBALS['nm_tests']['failed'];
    try {
        $fn();
    } catch (Throwable $e) {
        fail_test('uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    echo ($GLOBALS['nm_tests']['failed'] === $before ? "  ok    " : "  FAIL  ") . $name . PHP_EOL;
}

function fail_test(string $message): void
{
    $GLOBALS['nm_tests']['failed']++;
    $GLOBALS['nm_tests']['failures'][] = $GLOBALS['nm_tests']['current'] . ': ' . $message;
}

function check($condition, string $message = ''): void
{
    if ($condition) {
        $GLOBALS['nm_tests']['passed']++;
    } else {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        fail_test(($message !== '' ? $message : 'check failed') . ' (line ' . $bt[0]['line'] . ')');
    }
}

function check_same($expected, $actual, string $message = ''): void
{
    if ($expected === $actual) {
        $GLOBALS['nm_tests']['passed']++;
    } else {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        fail_test(($message !== '' ? $message . ' — ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ' (line ' . $bt[0]['line'] . ')');
    }
}

function tests_summary(): int
{
    $t = $GLOBALS['nm_tests'];
    echo PHP_EOL . $t['passed'] . ' checks passed, ' . $t['failed'] . ' failed' . PHP_EOL;
    foreach ($t['failures'] as $f) {
        echo '  - ' . $f . PHP_EOL;
    }
    return $t['failed'] > 0 ? 1 : 0;
}

/** Fresh private data folder for one test run. */
function tests_tmpdir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/nm-' . $prefix . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    return $dir;
}

function tests_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** Free TCP port on 127.0.0.1. */
function tests_free_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/** Starts a background PHP process; returns [proc, pipes]. Waits until $port accepts connections. */
function tests_spawn(array $cmd, int $port, array $env = []): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', sys_get_temp_dir() . '/nm-tests-spawn.log', 'a']];
    $proc = proc_open($cmd, $spec, $pipes, null, $env ? array_merge(getenv(), $env) : null);
    for ($i = 0; $i < 100; $i++) {
        $c = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
        if ($c) {
            fclose($c);
            return [$proc, $pipes];
        }
        usleep(100000);
    }
    throw new RuntimeException('Process did not start on port ' . $port . ': ' . implode(' ', $cmd));
}

function tests_kill(array $p): void
{
    if (is_resource($p[0])) {
        proc_terminate($p[0]);
        proc_close($p[0]);
    }
}
