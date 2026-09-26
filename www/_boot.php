<?php
/**
 * Finds the private "notifyme" folder and loads it. Included by every page.
 *
 * Search order:
 *   1. the path returned by notifyme-path.php, if you created that file
 *      (copy notifyme-path.php.example and edit it);
 *   2. the NOTIFYME_DIR environment variable;
 *   3. a "notifyme" folder next to this folder, or up to 3 levels above it
 *      (e.g. /home/you/notifyme when this folder is /home/you/public_html
 *      or /home/you/public_html/newsletter);
 *   4. a "notifyme" folder inside this folder (fallback when everything is
 *      uploaded in the web root; it is then protected by its .htaccess).
 */
define('NM_WEB_DIR', __DIR__);

(function () {
    $candidates = [];
    if (is_file(__DIR__ . '/notifyme-path.php')) {
        $candidates[] = (string) require __DIR__ . '/notifyme-path.php';
    }
    if (getenv('NOTIFYME_DIR')) {
        $candidates[] = (string) getenv('NOTIFYME_DIR');
    }
    $dir = __DIR__;
    for ($i = 0; $i < 4; $i++) {
        $dir = dirname($dir);
        $candidates[] = $dir . '/notifyme';
    }
    $candidates[] = __DIR__ . '/notifyme';

    foreach ($candidates as $c) {
        // is_file() may emit open_basedir warnings for folders outside the allowed paths.
        if ($c !== '' && @is_file(rtrim($c, '/\\') . '/bootstrap.php')) {
            require rtrim($c, '/\\') . '/bootstrap.php';
            return;
        }
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Notify Me List: le dossier privé « notifyme » est introuvable.\n"
        . "Placez-le à côté de votre dossier public (ex. /home/utilisateur/notifyme)\n"
        . "ou indiquez son chemin dans notifyme-path.php (voir notifyme-path.php.example).\n\n"
        . "Notify Me List: the private \"notifyme\" folder was not found.\n"
        . "Put it next to your public folder or set its path in notifyme-path.php.\n";
    exit;
})();
