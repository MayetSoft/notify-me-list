<?php
/**
 * Notify Me List — bootstrap.
 *
 * Loaded by every public page (through www/_boot.php) and by the cron scripts.
 * Defines paths, loads the library and the language file. Nothing here talks
 * to the network.
 *
 * License: MIT (see LICENSE).
 */

if (defined('NM_ROOT')) {
    return;
}

define('NM_VERSION', '1.0.0');
define('NM_ROOT', __DIR__);
define('NM_MIN_PHP', '7.4.0');

// Optional local overrides (not versioned). See config.local.php.example.
if (is_file(NM_ROOT . '/config.local.php')) {
    require NM_ROOT . '/config.local.php';
}

if (!defined('NM_DATA')) {
    define('NM_DATA', NM_ROOT . '/data');
}
define('NM_DB_FILE', NM_DATA . '/notifyme.sqlite');
define('NM_SECRET_FILE', NM_DATA . '/secret.php');
define('NM_LOCK_FILE', NM_DATA . '/installed.lock');
define('NM_LOG_DIR', NM_DATA . '/logs');
define('NM_EMERGENCY_FILE', NM_DATA . '/emergency-login.txt');

// Development only: allow feeds on private/loopback addresses. NEVER enable in
// production, it re-opens the SSRF protection. Define it in config.local.php.
if (!defined('NM_ALLOW_PRIVATE_FEEDS')) {
    define('NM_ALLOW_PRIVATE_FEEDS', false);
}

mb_internal_encoding('UTF-8');
date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');

require NM_ROOT . '/lib/helpers.php';
require NM_ROOT . '/lib/Db.php';
require NM_ROOT . '/lib/Crypto.php';
require NM_ROOT . '/lib/Tokens.php';
require NM_ROOT . '/lib/Smtp.php';
require NM_ROOT . '/lib/Mailer.php';
require NM_ROOT . '/lib/Subscribers.php';
require NM_ROOT . '/lib/FeedFetcher.php';
require NM_ROOT . '/lib/FeedParser.php';
require NM_ROOT . '/lib/Feeds.php';
require NM_ROOT . '/lib/Queue.php';

// The timezone setting (when installed) overrides the server default.
if (nm_is_installed()) {
    try {
        $tz = setting('timezone', '');
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }
    } catch (Throwable $e) {
        // Database unreadable: the page that needs it will report the error.
    }
}
