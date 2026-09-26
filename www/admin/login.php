<?php
/**
 * Admin login by magic link (no password anywhere).
 *
 * Emergency access: if e-mail sending is broken you cannot receive the link.
 * Create an empty file named emergency-login.txt in notifyme/data/ with your
 * hosting file manager, then reload this page: a one-time login button
 * appears (valid 1 hour after the file was created, the file is deleted
 * when used). Having access to the hosting files already means full control.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_session_start();

if (nm_is_admin()) {
    nm_redirect(nm_link('admin/index.php'));
}

$view = 'form';
$token = '';
$emergency = is_file(NM_EMERGENCY_FILE) ? (filemtime(NM_EMERGENCY_FILE) > nm_now() - 3600 ? 'valid' : 'expired') : 'none';

if (nm_get('token') !== '' && !nm_is_post()) {
    $token = nm_get('token');
    $view = Tokens::find($token, 'admin') ? 'landing' : 'invalid';
}

if (nm_is_post()) {
    $action = nm_post('action');
    if ($action === 'login') {
        // The token is the secret (no CSRF token by design).
        $row = Tokens::consume(nm_post('token'), 'admin');
        if ($row && strcasecmp($row['email'], (string) setting('admin_email', '')) === 0) {
            Tokens::revokeAll('admin', $row['email']);
            nm_admin_login((string) setting('admin_email'));
            nm_log('security', 'Admin login (magic link) from ' . nm_client_ip());
            nm_redirect(nm_link('admin/index.php'));
        }
        $view = 'invalid';
    } elseif ($action === 'request') {
        csrf_check();
        $email = nm_normalize_email(nm_post('email'));
        if (nm_valid_email($email) && strcasecmp($email, (string) setting('admin_email', '')) === 0) {
            Tokens::sendMagicLink('admin', $email);
        } elseif (nm_valid_email($email)) {
            Tokens::allowMailTo($email); // same rate-limit bookkeeping, no e-mail
            nm_log('security', 'Admin login requested for a non-admin address from ' . nm_client_ip());
        }
        $view = 'sent';
    } elseif ($action === 'emergency') {
        csrf_check();
        clearstatcache();
        if (is_file(NM_EMERGENCY_FILE) && filemtime(NM_EMERGENCY_FILE) > nm_now() - 3600 && @unlink(NM_EMERGENCY_FILE)) {
            nm_admin_login((string) setting('admin_email'));
            nm_log('security', 'Admin login (EMERGENCY FILE) from ' . nm_client_ip());
            flash('warning', t('login.emergency_used'));
            nm_redirect(nm_link('admin/index.php'));
        }
        flash('error', t('login.emergency_failed'));
        nm_redirect(nm_link('admin/login.php'));
    }
}

nm_layout_start('admin', t('login.title'));
?>
<div class="narrow">
<h1><?= e(t('login.title')) ?></h1>

<?php if ($view === 'form'): ?>
  <p><?= e(t('login.intro')) ?></p>
  <form method="post" action="<?= e(nm_link('admin/login.php')) ?>" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="request">
    <label for="email"><?= e(t('login.email')) ?></label>
    <input id="email" name="email" type="email" required autocomplete="email" autofocus>
    <button type="submit" class="btn btn-primary"><?= e(t('login.submit')) ?></button>
  </form>
<?php elseif ($view === 'sent'): ?>
  <div class="alert alert-success"><?= e(t('login.sent', ['minutes' => (int) (Tokens::MAGIC_TTL / 60)])) ?></div>
  <p class="muted"><?= e(t('login.not_received')) ?></p>
<?php elseif ($view === 'landing'): ?>
  <form method="post" action="<?= e(nm_link('admin/login.php')) ?>" class="card">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <p><?= e(t('login.landing')) ?></p>
    <button type="submit" class="btn btn-primary"><?= e(t('login.landing_button')) ?></button>
  </form>
<?php else: ?>
  <div class="alert alert-error"><?= e(t('magic.invalid')) ?></div>
  <p><a href="<?= e(nm_link('admin/login.php')) ?>"><?= e(t('magic.request_new')) ?></a></p>
<?php endif; ?>

<?php if ($emergency === 'valid'): ?>
  <div class="card danger-zone">
    <h2><?= e(t('login.emergency_title')) ?></h2>
    <p><?= e(t('login.emergency_body')) ?></p>
    <form method="post" action="<?= e(nm_link('admin/login.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="emergency">
      <button type="submit" class="btn btn-danger"><?= e(t('login.emergency_button')) ?></button>
    </form>
  </div>
<?php elseif ($emergency === 'expired'): ?>
  <div class="alert alert-warning"><?= e(t('login.emergency_expired')) ?></div>
<?php endif; ?>
</div>
<?php
nm_layout_end();
