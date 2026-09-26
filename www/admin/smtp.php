<?php
/**
 * Admin: SMTP settings + test e-mail. The password is stored encrypted
 * (AES-256-GCM, key in notifyme/data/secret.php).
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

$errors = [];
$testResult = null;
$transcript = [];
$storedPassword = (string) setting('smtp_password', '');
$passwordSet = $storedPassword !== '';
$keys = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_verify_cert', 'from_name', 'from_email', 'reply_to', 'smtp_timeout'];
$v = [];
foreach ($keys as $k) {
    $v[$k] = (string) setting($k, '');
}
$testTo = (string) setting('admin_email', '');

if (nm_is_post()) {
    csrf_check();
    foreach ($keys as $k) {
        $v[$k] = nm_post($k);
    }
    $v['smtp_verify_cert'] = nm_post('smtp_verify_cert') === '1' ? '1' : '0';
    $v['from_email'] = nm_normalize_email($v['from_email']);
    $v['reply_to'] = nm_normalize_email($v['reply_to']);
    if (!in_array($v['smtp_encryption'], ['none', 'tls', 'ssl'], true)) {
        $v['smtp_encryption'] = 'tls';
    }
    $port = (int) $v['smtp_port'];
    if ($port < 1 || $port > 65535) {
        $errors[] = t('smtp.err_port');
    }
    if ($v['smtp_host'] !== '' && !preg_match('/^[a-z0-9.\-]+$/i', $v['smtp_host'])) {
        $errors[] = t('smtp.err_host');
    }
    if ($v['from_email'] !== '' && !nm_valid_email($v['from_email'])) {
        $errors[] = t('smtp.err_from_email');
    }
    if ($v['reply_to'] !== '' && !nm_valid_email($v['reply_to'])) {
        $errors[] = t('smtp.err_reply_to');
    }
    $v['smtp_timeout'] = (string) max(5, min(120, (int) $v['smtp_timeout'] ?: 20));
    $newPassword = isset($_POST['smtp_password']) && is_string($_POST['smtp_password']) ? $_POST['smtp_password'] : '';
    $clear = nm_post('smtp_password_clear') === '1';

    if (!$errors) {
        if (nm_post('action') === 'test') {
            // Test the values of the form, even if not saved yet.
            $cfg = Mailer::config();
            $cfg['host'] = $v['smtp_host'];
            $cfg['port'] = $port;
            $cfg['encryption'] = $v['smtp_encryption'];
            $cfg['username'] = $v['smtp_username'];
            $cfg['verify_cert'] = $v['smtp_verify_cert'] === '1';
            $cfg['timeout'] = (int) $v['smtp_timeout'];
            if ($newPassword !== '') {
                $cfg['password'] = $newPassword;
            } elseif ($clear) {
                $cfg['password'] = '';
            }
            $cfg['from_email'] = $v['from_email'] !== '' ? $v['from_email'] : (string) setting('admin_email');
            $cfg['from_name'] = $v['from_name'] !== '' ? $v['from_name'] : nm_site_name();
            $cfg['reply_to'] = $v['reply_to'];
            $testTo = nm_normalize_email(nm_post('test_to'));
            if (!nm_valid_email($testTo)) {
                $errors[] = t('signup.err_email');
            } else {
                @set_time_limit(90);
                try {
                    Mailer::sendTest($cfg, $testTo, $transcript);
                    $testResult = ['success', t('smtp.test_ok', ['email' => $testTo])];
                } catch (Throwable $e) {
                    $testResult = ['error', t('smtp.test_failed', ['error' => $e->getMessage()])];
                }
            }
        } else {
            foreach ($keys as $k) {
                setting_set($k, $v[$k]);
            }
            setting_set('smtp_port', (string) $port);
            if ($newPassword !== '') {
                setting_set('smtp_password', Crypto::encrypt($newPassword));
            } elseif ($clear) {
                setting_set('smtp_password', '');
            }
            flash('success', t('common.saved'));
            nm_redirect(nm_link('admin/smtp.php'));
        }
    }
}

nm_layout_start('admin', t('nav.smtp'), 'smtp');
?>
<h1><?= e(t('nav.smtp')) ?></h1>
<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($testResult): ?>
  <div class="alert alert-<?= e($testResult[0]) ?>" role="status"><?= e($testResult[1]) ?></div>
  <?php if ($testResult[0] === 'success'): ?><p class="muted small"><?= e(t('smtp.test_not_saved_hint')) ?></p><?php endif; ?>
  <?php if ($transcript): ?>
    <details class="card"><summary><?= e(t('smtp.transcript')) ?></summary><pre class="code"><?= e(implode("\n", $transcript)) ?></pre></details>
  <?php endif; ?>
<?php endif; ?>

<form method="post" action="<?= e(nm_link('admin/smtp.php')) ?>" class="form">
  <?= csrf_field() ?>
  <section class="card">
    <p class="help"><?= e(t('smtp.intro')) ?></p>
    <?php require NM_ROOT . '/templates/smtp-fields.php'; ?>
    <div class="grid-2">
      <div>
        <label for="reply_to"><?= e(t('smtp.reply_to')) ?></label>
        <input id="reply_to" name="reply_to" type="email" value="<?= e($v['reply_to']) ?>">
      </div>
      <div>
        <label for="smtp_timeout"><?= e(t('smtp.timeout_label')) ?></label>
        <input id="smtp_timeout" name="smtp_timeout" type="number" min="5" max="120" value="<?= e($v['smtp_timeout']) ?>">
      </div>
    </div>
  </section>

  <section class="card">
    <h2><?= e(t('smtp.test_title')) ?></h2>
    <label for="test_to"><?= e(t('smtp.test_to')) ?></label>
    <input id="test_to" name="test_to" type="email" value="<?= e($testTo) ?>">
    <p class="help"><?= e(t('smtp.test_help')) ?></p>
    <div class="button-row">
      <button type="submit" name="action" value="save" class="btn btn-primary"><?= e(t('common.save')) ?></button>
      <button type="submit" name="action" value="test" class="btn"><?= e(t('smtp.test_button')) ?></button>
    </div>
  </section>
</form>

<section class="card">
  <h2><?= e(t('smtp.common_title')) ?></h2>
  <p class="help"><?= e(t('smtp.common_help')) ?></p>
</section>
<?php
nm_layout_end();
