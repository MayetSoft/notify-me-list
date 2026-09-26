<?php
/**
 * General helpers: translation, escaping, settings, sessions, CSRF, flash
 * messages, URLs, rate limiting, logging, text/HTML conversion.
 */

// ---------------------------------------------------------------------------
// Environment / requirements
// ---------------------------------------------------------------------------

function nm_is_cli(): bool
{
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}

function nm_is_installed(): bool
{
    return is_file(NM_LOCK_FILE) && is_file(NM_DB_FILE) && is_file(NM_SECRET_FILE);
}

/**
 * List of runtime requirements. Each entry: [label, ok(bool), required(bool), detail].
 */
function nm_requirements(): array
{
    $dataWritable = is_dir(NM_DATA) && is_writable(NM_DATA);
    return [
        ['PHP >= ' . NM_MIN_PHP, version_compare(PHP_VERSION, NM_MIN_PHP, '>='), true, PHP_VERSION],
        ['PDO + pdo_sqlite', extension_loaded('pdo_sqlite'), true, extension_loaded('pdo_sqlite') ? 'OK' : t('req.pdo_sqlite_missing')],
        ['openssl', extension_loaded('openssl'), true, t('req.openssl')],
        ['mbstring', extension_loaded('mbstring'), true, t('req.mbstring')],
        ['dom (DOMDocument)', extension_loaded('dom') && class_exists('DOMDocument'), true, t('req.dom')],
        ['curl', extension_loaded('curl'), false, t('req.curl')],
        [t('req.data_dir'), $dataWritable, true, NM_DATA],
    ];
}

function nm_missing_requirements(): array
{
    $missing = [];
    foreach (nm_requirements() as $r) {
        if ($r[2] && !$r[1]) {
            $missing[] = $r[0] . ' — ' . $r[3];
        }
    }
    return $missing;
}

/**
 * Stops the request with a readable error page (or a plain message in CLI).
 */
function nm_fail(string $title, string $message, int $httpCode = 500): void
{
    if (nm_is_cli()) {
        fwrite(STDERR, $title . ': ' . $message . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="' . e(nm_language_code()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title>'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f4;color:#1c1917;'
        . 'margin:0;padding:40px 16px}main{max-width:640px;margin:auto;background:#fff;border:1px solid #e7e5e4;'
        . 'border-radius:8px;padding:24px 28px}h1{font-size:1.3rem;margin-top:0;color:#b91c1c}pre{white-space:pre-wrap}</style>'
        . '</head><body><main><h1>' . e($title) . '</h1><div>' . nl2br(e($message)) . '</div></main></body></html>';
    exit;
}

/**
 * Checks extensions and installation; used at the top of every page except
 * install.php.
 */
function nm_require_ready(): void
{
    $missing = nm_missing_requirements();
    if ($missing) {
        nm_fail(t('err.requirements_title'), t('err.requirements_body') . "\n\n- " . implode("\n- ", $missing));
    }
    if (!nm_is_installed()) {
        if (nm_is_cli()) {
            nm_fail('Notify Me List', t('err.not_installed_cli'));
        }
        if (defined('NM_WEB_DIR') && is_file(NM_WEB_DIR . '/install.php')) {
            nm_redirect(nm_link('install.php'));
        }
        nm_fail(t('err.not_installed_title'), t('err.not_installed_body'), 503);
    }
}

// ---------------------------------------------------------------------------
// Translation & escaping
// ---------------------------------------------------------------------------

function nm_language_code(): string
{
    if (defined('NM_LANG')) {
        return (string) NM_LANG;
    }
    static $code = null;
    if ($code === null) {
        $code = 'fr';
        if (nm_is_installed() && extension_loaded('pdo_sqlite')) {
            try {
                $code = (string) setting('language', 'fr');
            } catch (Throwable $e) {
                $code = 'fr';
            }
        }
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $code) || !is_file(NM_ROOT . '/lang/' . $code . '.php')) {
            $code = 'fr';
        }
    }
    return $code;
}

/** Display name of a language file ("Français", "English"...). */
function nm_language_name(string $code): string
{
    $file = NM_ROOT . '/lang/' . $code . '.php';
    if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $code) || !is_file($file)) {
        return $code;
    }
    $strings = require $file;
    return isset($strings['language.name']) ? $strings['language.name'] : $code;
}

function nm_available_languages(): array
{
    $out = [];
    foreach (glob(NM_ROOT . '/lang/*.php') ?: [] as $f) {
        $out[] = basename($f, '.php');
    }
    sort($out);
    return $out;
}

/**
 * Translates a key from lang/<code>.php. Placeholders use {name} syntax.
 * Falls back to the French file, then to the key itself.
 */
function t(string $key, array $params = []): string
{
    static $strings = null;
    if ($strings === null) {
        $strings = require NM_ROOT . '/lang/fr.php';
        $code = nm_language_code();
        if ($code !== 'fr') {
            $strings = array_merge($strings, require NM_ROOT . '/lang/' . $code . '.php');
        }
    }
    $s = isset($strings[$key]) ? $strings[$key] : $key;
    if ($params) {
        $repl = [];
        foreach ($params as $k => $v) {
            $repl['{' . $k . '}'] = (string) $v;
        }
        $s = strtr($s, $repl);
    }
    return $s;
}

/** HTML-escape for output. */
function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Settings (stored in the `settings` table)
// ---------------------------------------------------------------------------

function nm_default_settings(): array
{
    return [
        'site_name' => 'Ma lettre d\'information',
        'admin_email' => '',
        'base_url' => '',
        'language' => 'fr',
        'timezone' => 'Europe/Paris',
        'optin_mode' => 'single',            // single | double
        'unsubscribe_confirm' => '0',        // 1 = show a confirmation button instead of immediate removal
        'general_list_label' => 'Annonces générales',
        'consent_text' => 'J\'accepte de recevoir des e-mails de {site} et j\'ai lu la politique de confidentialité. Je peux me désinscrire à tout moment.',
        'privacy_url' => '',
        'privacy_text' => '',
        'batch_size' => '20',
        'send_delay_ms' => '1000',
        'max_per_hour' => '200',
        'feed_max_items' => '10',
        'feed_subject' => 'Nouveautés sur {site}',
        'pending_days' => '30',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',          // none | tls (STARTTLS) | ssl
        'smtp_username' => '',
        'smtp_password' => '',               // encrypted (see Crypto)
        'smtp_verify_cert' => '1',
        'smtp_timeout' => '20',
        'from_name' => '',
        'from_email' => '',
        'reply_to' => '',
        'schema_version' => '0',
    ];
}

function nm_settings_cache(?array $replace = null): array
{
    static $cache = null;
    if ($replace !== null) {
        $cache = $replace;
    }
    if ($cache === null) {
        $cache = nm_default_settings();
        $rows = db()->query('SELECT key, value FROM settings')->fetchAll();
        foreach ($rows as $r) {
            $cache[$r['key']] = $r['value'];
        }
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $all = nm_settings_cache();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function setting_int(string $key, int $min, int $max): int
{
    $v = (int) setting($key, 0);
    return max($min, min($max, $v));
}

function setting_set(string $key, $value): void
{
    $stmt = db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $stmt->execute([$key, (string) $value]);
    $all = nm_settings_cache();
    $all[$key] = (string) $value;
    nm_settings_cache($all);
}

function nm_site_name(): string
{
    return (string) setting('site_name', 'Notify Me List');
}

function nm_general_label(): string
{
    $l = trim((string) setting('general_list_label', ''));
    return $l !== '' ? $l : t('general.default_label');
}

function nm_consent_text(): string
{
    return strtr((string) setting('consent_text', ''), ['{site}' => nm_site_name()]);
}

// ---------------------------------------------------------------------------
// Time, logging
// ---------------------------------------------------------------------------

function nm_now(): int
{
    return time();
}

function nm_format_date($ts, bool $withTime = true): string
{
    if ($ts === null || $ts === '' || (int) $ts <= 0) {
        return '—';
    }
    return date($withTime ? t('format.datetime') : t('format.date'), (int) $ts);
}

function nm_log(string $channel, string $message): void
{
    if (!is_dir(NM_LOG_DIR)) {
        @mkdir(NM_LOG_DIR, 0750, true);
    }
    $file = NM_LOG_DIR . '/' . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '.log';
    // Keep log files small on shared hosting: rotate at ~1 MB.
    if (is_file($file) && filesize($file) > 1024 * 1024) {
        @rename($file, $file . '.1');
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    if (nm_is_cli() && getenv('NM_VERBOSE')) {
        echo $line;
    }
}

// ---------------------------------------------------------------------------
// Request helpers
// ---------------------------------------------------------------------------

function nm_post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function nm_get(string $key, string $default = ''): string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : $default;
}

/** Returns an array of positive ints from a POST array field (e.g. feeds[]). */
function nm_post_ids(string $key): array
{
    $out = [];
    if (isset($_POST[$key]) && is_array($_POST[$key])) {
        foreach ($_POST[$key] as $v) {
            if (is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0) {
                $out[(int) $v] = (int) $v;
            }
        }
    }
    return array_values($out);
}

function nm_is_post(): bool
{
    return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
}

function nm_client_ip(): string
{
    // Only REMOTE_ADDR is trusted: X-Forwarded-For can be forged by anyone.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function nm_valid_email(string $email): bool
{
    if (strlen($email) > 254 || preg_match('/[\r\n\s<>,;"]/', $email)) {
        return false;
    }
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function nm_normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function nm_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    // Common on shared hosting behind a TLS-terminating proxy.
    return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
}

// ---------------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------------

/** Absolute URL for e-mails and cron output, built from the base_url setting. */
function nm_abs_url(string $path, array $query = []): string
{
    $base = rtrim((string) setting('base_url', ''), '/');
    $url = $base . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

/**
 * Relative link to a page of the public folder, usable from any page
 * (including those in admin/). Independent from the base_url setting so the
 * admin never gets locked out by a wrong base URL.
 */
function nm_link(string $path, array $query = []): string
{
    static $prefix = null;
    if ($prefix === null) {
        $prefix = '';
        if (defined('NM_WEB_DIR') && isset($_SERVER['SCRIPT_FILENAME'])) {
            $scriptDir = realpath(dirname((string) $_SERVER['SCRIPT_FILENAME']));
            $webDir = realpath(NM_WEB_DIR);
            if ($scriptDir && $webDir && $scriptDir !== $webDir && strpos($scriptDir, $webDir) === 0) {
                $depth = substr_count(substr($scriptDir, strlen($webDir)), DIRECTORY_SEPARATOR);
                $prefix = str_repeat('../', $depth);
            }
        }
    }
    $url = $prefix . ltrim($path, '/');
    if ($url === '') {
        $url = './';
    }
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

function nm_redirect(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Guess the public URL of the www folder from the current request (installer). */
function nm_detect_base_url(): string
{
    $scheme = nm_is_https() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string) $_SERVER['HTTP_HOST']) : 'localhost';
    $dir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])) : '';
    $dir = rtrim($dir, '/');
    return $scheme . '://' . $host . $dir;
}

// ---------------------------------------------------------------------------
// Session, CSRF, flash messages
// ---------------------------------------------------------------------------

function nm_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || nm_is_cli()) {
        return;
    }
    session_name('nm_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => nm_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function csrf_token(): string
{
    nm_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Validates the CSRF token of the current POST request or stops with 400. */
function csrf_check(): void
{
    nm_session_start();
    $sent = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if ($sent === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $sent = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        nm_fail(t('err.csrf_title'), t('err.csrf_body'), 400);
    }
}

function flash(string $type, string $message): void
{
    nm_session_start();
    $_SESSION['flash'][] = [$type, $message];
}

function flash_take(): array
{
    nm_session_start();
    $msgs = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $msgs;
}

// ---------------------------------------------------------------------------
// Admin session
// ---------------------------------------------------------------------------

const NM_ADMIN_IDLE = 7200;       // 2 h without activity
const NM_ADMIN_ABSOLUTE = 43200;  // 12 h max

function nm_admin_login(string $email): void
{
    nm_session_start();
    session_regenerate_id(true);
    $_SESSION['admin'] = ['email' => $email, 'since' => nm_now(), 'seen' => nm_now()];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function nm_admin_logout(): void
{
    nm_session_start();
    unset($_SESSION['admin']);
    session_regenerate_id(true);
}

function nm_is_admin(): bool
{
    nm_session_start();
    if (empty($_SESSION['admin']['email'])) {
        return false;
    }
    $a = $_SESSION['admin'];
    $now = nm_now();
    if ($now - $a['seen'] > NM_ADMIN_IDLE || $now - $a['since'] > NM_ADMIN_ABSOLUTE
        || strcasecmp($a['email'], (string) setting('admin_email', '')) !== 0) {
        unset($_SESSION['admin']);
        return false;
    }
    $_SESSION['admin']['seen'] = $now;
    return true;
}

/** Guard for every admin page. */
function nm_require_admin(bool $json = false): void
{
    if (!nm_is_admin()) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => t('admin.session_expired')]);
            exit;
        }
        nm_redirect(nm_link('admin/login.php'));
    }
}

// ---------------------------------------------------------------------------
// Subscriber self-service session
// ---------------------------------------------------------------------------

const NM_SUBSCRIBER_SESSION = 1800; // 30 min

function nm_subscriber_login(int $subscriberId): void
{
    nm_session_start();
    session_regenerate_id(true);
    $_SESSION['subscriber'] = ['id' => $subscriberId, 'since' => nm_now()];
}

function nm_subscriber_id(): ?int
{
    nm_session_start();
    if (empty($_SESSION['subscriber']['id'])) {
        return null;
    }
    if (nm_now() - $_SESSION['subscriber']['since'] > NM_SUBSCRIBER_SESSION) {
        unset($_SESSION['subscriber']);
        return null;
    }
    return (int) $_SESSION['subscriber']['id'];
}

function nm_subscriber_logout(): void
{
    nm_session_start();
    unset($_SESSION['subscriber']);
}

// ---------------------------------------------------------------------------
// Rate limiting (stored in SQLite, works across processes)
// ---------------------------------------------------------------------------

/**
 * Records a hit in $bucket if fewer than $max hits happened in the last
 * $window seconds. Returns false (and records nothing) when over the limit.
 */
function nm_rate_limit(string $bucket, int $max, int $window): bool
{
    $pdo = db();
    $now = nm_now();
    $bucket = substr($bucket, 0, 300);
    if (mt_rand(1, 50) === 1) {
        $pdo->prepare('DELETE FROM rate_events WHERE created_at < ?')->execute([$now - 86400]);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_events WHERE bucket = ? AND created_at > ?');
    $stmt->execute([$bucket, $now - $window]);
    if ((int) $stmt->fetchColumn() >= $max) {
        return false;
    }
    $pdo->prepare('INSERT INTO rate_events (bucket, created_at) VALUES (?, ?)')->execute([$bucket, $now]);
    return true;
}

// ---------------------------------------------------------------------------
// Page layout helpers
// ---------------------------------------------------------------------------

/**
 * Starts buffering a page. Call nm_layout_end() at the bottom.
 * $layout: 'public' or 'admin'. $active: admin nav key.
 */
function nm_layout_start(string $layout, string $title, string $active = ''): void
{
    $GLOBALS['nm_layout'] = ['layout' => $layout, 'title' => $title, 'active' => $active];
    ob_start();
}

function nm_layout_end(): void
{
    $content = ob_get_clean();
    $layout = $GLOBALS['nm_layout']['layout'];
    $title = $GLOBALS['nm_layout']['title'];
    $active = $GLOBALS['nm_layout']['active'];
    $flashes = flash_take();
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: ' . ($layout === 'admin' ? 'DENY' : 'SAMEORIGIN'));
    }
    require NM_ROOT . '/templates/layout-' . ($layout === 'admin' ? 'admin' : 'public') . '.php';
}

/** Renders a PHP template file with variables and returns the output. */
function nm_render(string $file, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require $file;
    return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// Text utilities
// ---------------------------------------------------------------------------

function nm_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function nm_b64url_decode(string $s)
{
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($s, '-_', '+/'), true);
}

/** Plain text → simple HTML (paragraphs, line breaks, clickable links). */
function nm_text_to_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $paras = preg_split('/\n{2,}/', $text);
    $html = '';
    foreach ($paras as $p) {
        $escaped = e($p);
        $escaped = preg_replace_callback(
            '~\bhttps?://[^\s<>"\']+~i',
            function ($m) {
                $url = rtrim($m[0], '.,;:!?)');
                $rest = substr($m[0], strlen($url));
                return '<a href="' . $url . '">' . $url . '</a>' . $rest;
            },
            $escaped
        );
        $html .= '<p>' . nl2br($escaped, false) . "</p>\n";
    }
    return $html;
}

/** Simple HTML → readable plain text (for multipart fallbacks). */
function nm_html_to_text(string $html): string
{
    $html = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', '', $html);
    $html = preg_replace_callback(
        '~<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is',
        function ($m) {
            $label = trim(strip_tags($m[3]));
            $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($label === '' || $label === $href) {
                return $href;
            }
            return $label . ' (' . $href . ')';
        },
        $html
    );
    $html = preg_replace('~<li\b[^>]*>~i', "\n - ", $html);
    $html = preg_replace('~<br\s*/?>~i', "\n", $html);
    $html = preg_replace('~</(p|div|h[1-6]|ul|ol|table|tr|blockquote)>~i', "\n\n", $html);
    $html = preg_replace('~<(h[1-6])\b[^>]*>~i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/ *\n */", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

/** Strips tags, decodes entities, collapses spaces, truncates on a word. */
function nm_excerpt(string $html, int $max = 280): string
{
    $text = html_entity_decode(strip_tags(preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $max * 0.6) {
        $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, " ,.;:-") . '…';
}

/** Only keep http(s) URLs (prevents javascript: links in e-mails). */
function nm_safe_url(string $url): string
{
    $url = trim($url);
    return preg_match('~^https?://~i', $url) ? $url : '';
}

/** Human readable list of what a subscriber is subscribed to. */
function nm_describe_lists(bool $general, array $feedNames): array
{
    $out = [];
    if ($general) {
        $out[] = nm_general_label();
    }
    foreach ($feedNames as $n) {
        $out[] = $n;
    }
    return $out;
}
