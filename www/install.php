<?php
/**
 * One-time setup wizard.
 *
 * Creates the secret keys, the SQLite database and the settings, then writes
 * notifyme/data/installed.lock. As long as that lock file exists this page
 * refuses to run again. You may (and should) delete install.php afterwards.
 * To reinstall from scratch, delete the notifyme/data/ files (see README).
 */
require __DIR__ . '/_boot.php';

// Interface language of the wizard (and default language of the install).
$nmInstallLang = isset($_REQUEST['lang']) && is_string($_REQUEST['lang']) ? $_REQUEST['lang'] : 'fr';
if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $nmInstallLang) || !is_file(NM_ROOT . '/lang/' . $nmInstallLang . '.php')) {
    $nmInstallLang = 'fr';
}
if (!defined('NM_LANG') && !nm_is_installed()) {
    define('NM_LANG', $nmInstallLang);
}

function nm_install_page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    echo '<!DOCTYPE html><html lang="' . e(nm_language_code()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . e($title) . '</title><link rel="stylesheet" href="assets/style.css?v=' . e(NM_VERSION) . '"></head>'
        . '<body class="admin"><header class="admin-header"><div class="wrap"><span class="brand">Notify Me List <span class="muted">· '
        . e(t('install.title')) . '</span></span></div></header><main class="wrap narrow">' . $body . '</main>'
        . '<script src="assets/app.js?v=' . e(NM_VERSION) . '"></script></body></html>';
    exit;
}

if (nm_is_installed()) {
    nm_install_page(t('install.already_title'), '<h1>' . e(t('install.already_title')) . '</h1>'
        . '<div class="alert alert-info">' . e(t('install.already_body')) . '</div>'
        . '<p><a class="btn btn-primary" href="admin/login.php">' . e(t('install.go_admin')) . '</a></p>');
}

nm_session_start();
$requirements = nm_requirements();
$missing = nm_missing_requirements();
$errors = [];
$v = [
    'site_name' => t('install.default_site_name'),
    'admin_email' => '',
    'base_url' => nm_detect_base_url(),
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_encryption' => 'tls',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_verify_cert' => '1',
    'from_name' => '',
    'from_email' => '',
    'send_test' => '1',
];

if (nm_is_post() && !$missing) {
    csrf_check();
    foreach ($v as $k => $_) {
        $v[$k] = $k === 'smtp_password' ? (isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '') : nm_post($k);
    }
    $v['admin_email'] = nm_normalize_email($v['admin_email']);
    $v['from_email'] = nm_normalize_email($v['from_email']);
    $v['base_url'] = rtrim($v['base_url'], '/');

    if ($v['site_name'] === '') {
        $errors[] = t('settings.err_site_name');
    }
    if (!nm_valid_email($v['admin_email'])) {
        $errors[] = t('install.err_admin_email');
    }
    if (!preg_match('~^https?://[^\s/]+~i', $v['base_url'])) {
        $errors[] = t('settings.err_base_url');
    }
    if ($v['from_email'] !== '' && !nm_valid_email($v['from_email'])) {
        $errors[] = t('smtp.err_from_email');
    }
    if (!in_array($v['smtp_encryption'], ['none', 'tls', 'ssl'], true)) {
        $v['smtp_encryption'] = 'tls';
    }
    $port = (int) $v['smtp_port'];
    if ($v['smtp_host'] !== '' && ($port < 1 || $port > 65535)) {
        $errors[] = t('smtp.err_port');
    }

    if (!$errors) {
        try {
            if (!is_dir(NM_LOG_DIR)) {
                @mkdir(NM_LOG_DIR, 0750, true);
            }
            Crypto::generateSecretFile();
            db_migrate();
            $settings = [
                'site_name' => $v['site_name'],
                'admin_email' => $v['admin_email'],
                'base_url' => $v['base_url'],
                'smtp_host' => $v['smtp_host'],
                'smtp_port' => (string) ($port ?: 587),
                'smtp_encryption' => $v['smtp_encryption'],
                'smtp_username' => $v['smtp_username'],
                'smtp_password' => Crypto::encrypt($v['smtp_password']),
                'smtp_verify_cert' => $v['smtp_verify_cert'] === '1' ? '1' : '0',
                'from_name' => $v['from_name'],
                'from_email' => $v['from_email'],
                // Texts shown to subscribers, created in the chosen language.
                'language' => $nmInstallLang,
                'general_list_label' => t('general.default_label'),
                'consent_text' => t('default.consent_text'),
                'feed_subject' => t('default.feed_subject'),
            ];
            foreach ($settings as $k => $val) {
                setting_set($k, $val);
            }

            $testResult = null;
            if ($v['send_test'] === '1' && $v['smtp_host'] !== '') {
                $transcript = [];
                try {
                    Mailer::sendTest(Mailer::config(), $v['admin_email'], $transcript);
                    $testResult = ['success', t('smtp.test_ok', ['email' => $v['admin_email']])];
                } catch (Throwable $e) {
                    $testResult = ['error', t('smtp.test_failed', ['error' => $e->getMessage()])];
                }
            }

            if (@file_put_contents(NM_LOCK_FILE, 'Installed ' . date('c') . ' — version ' . NM_VERSION . "\n", LOCK_EX) === false) {
                throw new RuntimeException(t('install.cannot_write', ['file' => NM_LOCK_FILE]));
            }
            nm_log('app', 'Installation completed (version ' . NM_VERSION . ')');
            nm_admin_login($v['admin_email']);

            $cronLines = CronSetup::lines();
            $body = '<h1>' . e(t('install.done_title')) . '</h1>';
            if ($testResult) {
                $body .= '<div class="alert alert-' . e($testResult[0]) . '">' . e($testResult[1]) . '</div>';
            }
            $body .= '<div class="alert alert-success">' . e(t('install.done_body')) . '</div>'
                . '<h2>' . e(t('install.next_steps')) . '</h2><ol class="steps">'
                . '<li>' . e(t('install.step_delete')) . '</li>'
                . '<li>' . e(t('install.step_cron')) . ' <a href="admin/cron.php">' . e(t('install.step_cron_link')) . '</a>'
                . '<pre class="code">' . e(implode("\n", $cronLines)) . '</pre>'
                . '<p class="muted small">' . e(t('install.step_cron_php')) . '</p></li>'
                . '<li>' . e(t('install.step_feeds')) . '</li></ol>'
                . '<p><a class="btn btn-primary" href="admin/index.php">' . e(t('install.go_admin')) . '</a></p>';
            nm_install_page(t('install.done_title'), $body);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

ob_start();
?>
<h1><?= e(t('install.title')) ?></h1>
<p class="lang-switch">
  <?php foreach (nm_available_languages() as $l): ?>
    <?= $l === $nmInstallLang ? '<strong>' . e(nm_language_name($l)) . '</strong>' : '<a href="install.php?lang=' . e($l) . '">' . e(nm_language_name($l)) . '</a>' ?>
  <?php endforeach; ?>
</p>
<p class="lead"><?= e(t('install.intro')) ?></p>

<section class="card">
  <h2><?= e(t('install.requirements')) ?></h2>
  <table class="table">
    <?php foreach ($requirements as $r): ?>
      <tr>
        <td><?= e($r[0]) ?><?= $r[2] ? '' : ' <span class="muted">(' . e(t('install.optional')) . ')</span>' ?></td>
        <td><?= $r[1] ? '<span class="badge badge-ok">OK</span>' : '<span class="badge ' . ($r[2] ? 'badge-error' : 'badge-warn') . '">' . e(t('install.missing')) . '</span>' ?></td>
        <td class="muted small"><?= e($r[3]) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($missing): ?>
    <div class="alert alert-error"><?= e(t('install.fix_requirements')) ?></div>
  <?php endif; ?>
</section>

<?php if (!$missing): ?>
  <?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post" action="install.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="lang" value="<?= e($nmInstallLang) ?>">
    <section class="card">
      <h2><?= e(t('install.section_site')) ?></h2>
      <label for="site_name"><?= e(t('settings.site_name')) ?></label>
      <input id="site_name" name="site_name" required maxlength="120" value="<?= e($v['site_name']) ?>">

      <label for="admin_email"><?= e(t('settings.admin_email')) ?></label>
      <input id="admin_email" name="admin_email" type="email" required value="<?= e($v['admin_email']) ?>">
      <p class="help"><?= e(t('install.admin_email_help')) ?></p>

      <label for="base_url"><?= e(t('settings.base_url')) ?></label>
      <input id="base_url" name="base_url" type="url" required value="<?= e($v['base_url']) ?>">
      <p class="help"><?= e(t('settings.base_url_help')) ?></p>
    </section>

    <section class="card">
      <h2><?= e(t('install.section_smtp')) ?></h2>
      <p class="help"><?= e(t('smtp.intro')) ?></p>
      <?php require NM_ROOT . '/templates/smtp-fields.php'; ?>
      <label class="check"><input type="checkbox" name="send_test" value="1"<?= $v['send_test'] === '1' ? ' checked' : '' ?>> <span><?= e(t('install.send_test')) ?></span></label>
    </section>

    <button type="submit" class="btn btn-primary"><?= e(t('install.submit')) ?></button>
  </form>
<?php endif; ?>
<?php
nm_install_page(t('install.title'), (string) ob_get_clean());
