<?php
// Prints a one-time admin login link (valid 15 minutes). For people with SSH
// access whose e-mail sending is broken. Without SSH, use the emergency file
// described in www/admin/login.php and the README.
//
//   php /home/YOUR_USER/notifyme/cron/admin-link.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require dirname(__DIR__) . '/bootstrap.php';
nm_require_ready();
$email = (string) setting('admin_email');
$raw = Tokens::create('admin', $email, null, Tokens::MAGIC_TTL);
nm_log('security', 'Admin login link generated from the command line');
echo nm_abs_url('admin/login.php', ['token' => $raw]) . PHP_EOL;
