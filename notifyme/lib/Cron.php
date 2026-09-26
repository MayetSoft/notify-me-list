<?php
/**
 * Helps set up the two cron jobs.
 *
 * Three ways, from the most to the least automatic:
 *  1. the `crontab` command, when the host lets PHP run it (proc_open);
 *  2. the cPanel API (API 2 Cron::fetchcron / Cron::add_line) with an API
 *     token the admin creates in cPanel > Security > Manage API Tokens. The
 *     token is used for this request only and never stored;
 *  3. copy-paste of the exact lines into cPanel > Cron Jobs.
 * Our lines are recognised by the path of our scripts, so re-installing
 * replaces them instead of adding duplicates.
 */
final class CronSetup
{
    /** The two jobs: key => [minute field, script]. */
    public static function jobs(): array
    {
        return [
            'feeds' => ['*/15', NM_ROOT . '/cron/check-feeds.php'],
            'queue' => ['*/5', NM_ROOT . '/cron/send-queue.php'],
        ];
    }

    /** Command-line PHP to use in cron: admin override, else auto-detected. */
    public static function phpCommand(): string
    {
        $custom = nm_is_installed() ? trim((string) setting('cron_php', '')) : '';
        return $custom !== '' ? $custom : self::detectPhp();
    }

    /**
     * Finds the command-line PHP matching the version running the site. The
     * web server often runs php-fpm / lsphp / php-cgi, which must not be used.
     */
    public static function detectPhp(): string
    {
        $v = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $candidates = [];
        if (nm_is_cli() && PHP_BINARY !== '') {
            $candidates[] = PHP_BINARY;
        }
        if (PHP_BINARY !== '') {
            $dir = dirname(PHP_BINARY);
            $candidates[] = $dir . '/php';
            $candidates[] = dirname($dir) . '/bin/php';          // .../sbin/php-fpm -> .../bin/php
        }
        $candidates[] = '/opt/cpanel/ea-php' . $v . '/root/usr/bin/php'; // cPanel EasyApache 4
        $candidates[] = '/opt/alt/php' . $v . '/usr/bin/php';            // CloudLinux alt-php
        $candidates[] = '/usr/local/bin/ea-php' . $v;
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        foreach ($candidates as $c) {
            if (preg_match('/(cgi|fpm|lsphp)/i', basename($c))) {
                continue;
            }
            // @: open_basedir may forbid looking outside the account.
            if (@is_file($c) && @is_executable($c)) {
                return $c;
            }
        }
        return 'php';
    }

    public static function command(string $script): string
    {
        $path = preg_match('/^[A-Za-z0-9_\/.\-]+$/', $script) ? $script : escapeshellarg($script);
        return self::phpCommand() . ' ' . $path . ' >/dev/null 2>&1';
    }

    /** Full crontab lines, e.g. "*\/15 * * * * php /home/u/notifyme/cron/check-feeds.php >/dev/null 2>&1". */
    public static function lines(): array
    {
        $out = [];
        foreach (self::jobs() as $key => $job) {
            $out[$key] = $job[0] . ' * * * * ' . self::command($job[1]);
        }
        return $out;
    }

    /** Comment line introducing this instance's jobs (several instances can share a crontab). */
    public static function marker(): string
    {
        return '# Notify Me List — ' . NM_ROOT;
    }

    /** Is this line one of ours (any PHP binary, any options)? Returns the job key or null. */
    public static function ownLine(string $line): ?string
    {
        foreach (self::jobs() as $key => $job) {
            if (strpos($line, $job[1]) !== false && strpos(ltrim($line), '#') !== 0) {
                return $key;
            }
        }
        return null;
    }

    // --- 1. crontab command ---------------------------------------------------

    private static function canExec(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
        return !in_array('proc_open', $disabled, true);
    }

    /** Runs a command without a shell. Returns [exit code, stdout, stderr]. */
    private static function run(array $cmd, string $stdin = ''): array
    {
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException(t('cron.err_exec'));
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    private static function crontabBinary(): ?string
    {
        foreach (['/usr/bin/crontab', '/bin/crontab', '/usr/local/bin/crontab'] as $c) {
            if (@is_file($c) && @is_executable($c)) {
                return $c;
            }
        }
        return null;
    }

    /** Can we read the account's crontab from PHP? */
    public static function crontabAvailable(): bool
    {
        if (!self::canExec() || !self::crontabBinary()) {
            return false;
        }
        try {
            self::crontabRead();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function crontabRead(): string
    {
        $bin = self::crontabBinary();
        if (!$bin || !self::canExec()) {
            throw new RuntimeException(t('cron.err_exec'));
        }
        list($code, $out, $err) = self::run([$bin, '-l']);
        if ($code !== 0) {
            if (stripos($err, 'no crontab') !== false) {
                return '';
            }
            throw new RuntimeException(t('cron.err_crontab', ['error' => trim($err) ?: 'exit ' . $code]));
        }
        return $out;
    }

    /** Current state of our jobs in the crontab: key => line|null. */
    public static function crontabStatus(): array
    {
        $found = array_fill_keys(array_keys(self::jobs()), null);
        foreach (preg_split('/\r?\n/', self::crontabRead()) as $line) {
            $key = self::ownLine($line);
            if ($key !== null) {
                $found[$key] = trim($line);
            }
        }
        return $found;
    }

    /** Adds (or replaces) our two lines, keeping every other line untouched. */
    public static function crontabInstall(bool $remove = false): void
    {
        $bin = self::crontabBinary();
        $marker = self::marker();
        $lines = preg_split('/\r?\n/', self::crontabRead());
        $kept = [];
        foreach ($lines as $i => $line) {
            if (self::ownLine($line) !== null || trim($line) === $marker) {
                continue;
            }
            // Comment written by 1.0.x (not instance-specific): drop it only when it
            // introduces this instance's own lines, never another instance's.
            if (trim($line) === '# Notify Me List' && isset($lines[$i + 1]) && self::ownLine($lines[$i + 1]) !== null) {
                continue;
            }
            $kept[] = $line;
        }
        while ($kept && trim(end($kept)) === '') {
            array_pop($kept);
        }
        if (!$remove) {
            $kept[] = $marker;
            foreach (self::lines() as $l) {
                $kept[] = $l;
            }
        }
        $content = $kept ? implode("\n", $kept) . "\n" : '';
        list($code, , $err) = self::run([$bin, '-'], $content);
        if ($code !== 0) {
            throw new RuntimeException(t('cron.err_crontab', ['error' => trim($err) ?: 'exit ' . $code]));
        }
        // Verify.
        $status = self::crontabStatus();
        foreach ($status as $line) {
            if (($line === null) !== $remove) {
                throw new RuntimeException(t('cron.err_verify'));
            }
        }
        nm_log('app', $remove ? 'Cron jobs removed from crontab' : 'Cron jobs installed in crontab');
    }

    // --- 2. cPanel API ----------------------------------------------------------

    /**
     * Installs the missing jobs through the cPanel API with a one-time API
     * token. Returns ['added' => [keys], 'present' => [keys]].
     */
    public static function cpanelInstall(string $host, int $port, string $user, string $token, bool $verifyTls = true): array
    {
        if (!preg_match('/^[a-z0-9.\-]+$/i', $host) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException(t('cron.err_cpanel_host'));
        }
        if (!preg_match('/^[a-z0-9_.\-]+$/i', $user) || !preg_match('/^[A-Za-z0-9]{10,}$/', $token)) {
            throw new InvalidArgumentException(t('cron.err_cpanel_credentials'));
        }
        $present = [];
        foreach (self::cpanelCall($host, $port, $user, $token, $verifyTls, 'fetchcron', []) as $row) {
            $key = isset($row['command']) ? self::ownLine((string) $row['command']) : null;
            if ($key !== null) {
                $present[$key] = true;
            }
        }
        $added = [];
        foreach (self::jobs() as $key => $job) {
            if (isset($present[$key])) {
                continue;
            }
            $data = self::cpanelCall($host, $port, $user, $token, $verifyTls, 'add_line', [
                'command' => self::command($job[1]),
                'minute' => $job[0], 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*',
            ]);
            if (isset($data[0]['status']) && (int) $data[0]['status'] !== 1) {
                throw new RuntimeException(t('cron.err_cpanel', ['error' => isset($data[0]['statusmsg']) ? $data[0]['statusmsg'] : 'add_line']));
            }
            $added[] = $key;
        }
        nm_log('app', 'Cron jobs via cPanel API: added ' . (implode(', ', $added) ?: 'none'));
        return ['added' => $added, 'present' => array_keys($present)];
    }

    private static function cpanelCall(string $host, int $port, string $user, string $token, bool $verifyTls, string $func, array $params): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(t('cron.err_curl'));
        }
        $url = 'https://' . $host . ':' . $port . '/json-api/cpanel?' . http_build_query(array_merge([
            'cpanel_jsonapi_user' => $user,
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module' => 'Cron',
            'cpanel_jsonapi_func' => $func,
        ], $params), '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $user . ':' . $token],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException(t('cron.err_cpanel', ['error' => $err]));
        }
        $json = json_decode((string) $body, true);
        if ($status === 401 || $status === 403) {
            throw new RuntimeException(t('cron.err_cpanel_credentials'));
        }
        if (!is_array($json) || !isset($json['cpanelresult'])) {
            throw new RuntimeException(t('cron.err_cpanel', ['error' => 'HTTP ' . $status]));
        }
        $r = $json['cpanelresult'];
        if (!empty($r['error'])) {
            throw new RuntimeException(t('cron.err_cpanel', ['error' => (string) $r['error']]));
        }
        return isset($r['data']) && is_array($r['data']) ? $r['data'] : [];
    }
}
