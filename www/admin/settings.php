<?php
/**
 * Admin: general settings, opt-in mode, sending limits, texts.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

$errors = [];
$fields = [
    'site_name', 'admin_email', 'base_url', 'language', 'timezone', 'optin_mode', 'unsubscribe_confirm',
    'general_list_label', 'consent_text', 'privacy_url', 'privacy_text', 'batch_size', 'send_delay_ms',
    'max_per_hour', 'feed_max_items', 'feed_subject', 'pending_days',
];
$v = [];
foreach ($fields as $f) {
    $v[$f] = (string) setting($f, '');
}

if (nm_is_post()) {
    csrf_check();
    foreach ($fields as $f) {
        $v[$f] = nm_post($f);
    }
    $v['unsubscribe_confirm'] = nm_post('unsubscribe_confirm') === '1' ? '1' : '0';
    $v['optin_mode'] = nm_post('optin_mode') === 'double' ? 'double' : 'single';
    $v['admin_email'] = nm_normalize_email($v['admin_email']);
    $v['base_url'] = rtrim($v['base_url'], '/');

    if ($v['site_name'] === '' || mb_strlen($v['site_name']) > 120) {
        $errors[] = t('settings.err_site_name');
    }
    if (!nm_valid_email($v['admin_email'])) {
        $errors[] = t('install.err_admin_email');
    }
    if (!preg_match('~^https?://[^\s/]+~i', $v['base_url'])) {
        $errors[] = t('settings.err_base_url');
    }
    if ($v['privacy_url'] !== '' && !nm_safe_url($v['privacy_url'])) {
        $errors[] = t('settings.err_privacy_url');
    }
    if (!in_array($v['language'], nm_available_languages(), true)) {
        $v['language'] = 'fr';
    }
    if (!in_array($v['timezone'], timezone_identifiers_list(), true)) {
        $errors[] = t('settings.err_timezone');
    }
    if (trim($v['consent_text']) === '') {
        $errors[] = t('settings.err_consent');
    }
    $v['batch_size'] = (string) max(1, min(500, (int) $v['batch_size']));
    $v['send_delay_ms'] = (string) max(0, min(60000, (int) $v['send_delay_ms']));
    $v['max_per_hour'] = (string) max(0, min(1000000, (int) $v['max_per_hour']));
    $v['feed_max_items'] = (string) max(1, min(50, (int) $v['feed_max_items']));
    $v['pending_days'] = (string) max(1, min(3650, (int) $v['pending_days']));

    if (!$errors) {
        $oldAdmin = (string) setting('admin_email', '');
        $oldMode = (string) setting('optin_mode', 'single');
        foreach ($fields as $f) {
            setting_set($f, $v[$f]);
        }
        if ($oldAdmin !== $v['admin_email']) {
            $_SESSION['admin']['email'] = $v['admin_email'];
            nm_log('security', 'Admin e-mail changed from ' . $oldAdmin . ' to ' . $v['admin_email']);
        }
        if ($oldMode !== $v['optin_mode']) {
            nm_log('app', 'Opt-in mode changed to ' . $v['optin_mode']);
        }
        flash('success', t('common.saved'));
        nm_redirect(nm_link('admin/settings.php'));
    }
}

$pending = (int) db_value("SELECT COUNT(*) FROM subscribers WHERE status = 'pending'");
nm_layout_start('admin', t('nav.settings'), 'settings');
?>
<h1><?= e(t('nav.settings')) ?></h1>
<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e(nm_link('admin/settings.php')) ?>" class="form">
  <?= csrf_field() ?>

  <section class="card">
    <h2><?= e(t('settings.section_optin')) ?></h2>
    <div class="toggle-group" role="radiogroup">
      <label class="radio-card">
        <input type="radio" name="optin_mode" value="single"<?= $v['optin_mode'] !== 'double' ? ' checked' : '' ?>>
        <span><strong><?= e(t('settings.optin_single')) ?></strong><br><span class="muted small"><?= e(t('settings.optin_single_help')) ?></span></span>
      </label>
      <label class="radio-card">
        <input type="radio" name="optin_mode" value="double"<?= $v['optin_mode'] === 'double' ? ' checked' : '' ?>>
        <span><strong><?= e(t('settings.optin_double')) ?></strong><br><span class="muted small"><?= e(t('settings.optin_double_help')) ?></span></span>
      </label>
    </div>
    <p class="help"><?= e(t('settings.optin_switch_help', ['n' => $pending])) ?></p>

    <label for="pending_days"><?= e(t('settings.pending_days')) ?></label>
    <input id="pending_days" name="pending_days" type="number" min="1" max="3650" value="<?= e($v['pending_days']) ?>">

    <label class="check"><input type="checkbox" name="unsubscribe_confirm" value="1"<?= $v['unsubscribe_confirm'] === '1' ? ' checked' : '' ?>>
      <span><?= e(t('settings.unsubscribe_confirm')) ?></span></label>
    <p class="help"><?= e(t('settings.unsubscribe_confirm_help')) ?></p>
  </section>

  <section class="card">
    <h2><?= e(t('settings.section_site')) ?></h2>
    <label for="site_name"><?= e(t('settings.site_name')) ?></label>
    <input id="site_name" name="site_name" required maxlength="120" value="<?= e($v['site_name']) ?>">

    <label for="admin_email"><?= e(t('settings.admin_email')) ?></label>
    <input id="admin_email" name="admin_email" type="email" required value="<?= e($v['admin_email']) ?>">
    <p class="help"><?= e(t('settings.admin_email_help')) ?></p>

    <label for="base_url"><?= e(t('settings.base_url')) ?></label>
    <input id="base_url" name="base_url" type="url" required value="<?= e($v['base_url']) ?>">
    <p class="help"><?= e(t('settings.base_url_help')) ?></p>

    <div class="grid-2">
      <div>
        <label for="language"><?= e(t('settings.language')) ?></label>
        <select id="language" name="language">
          <?php foreach (nm_available_languages() as $l): ?>
            <option value="<?= e($l) ?>"<?= $v['language'] === $l ? ' selected' : '' ?>><?= e(nm_language_name($l)) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="help"><?= e(t('settings.language_help')) ?></p>
      </div>
      <div>
        <label for="timezone"><?= e(t('settings.timezone')) ?></label>
        <input id="timezone" name="timezone" value="<?= e($v['timezone']) ?>" list="tz-list">
        <datalist id="tz-list"><?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= e($tz) ?>"><?php endforeach; ?></datalist>
      </div>
    </div>
  </section>

  <section class="card">
    <h2><?= e(t('settings.section_form')) ?></h2>
    <label for="general_list_label"><?= e(t('settings.general_list_label')) ?></label>
    <input id="general_list_label" name="general_list_label" maxlength="120" value="<?= e($v['general_list_label']) ?>">
    <p class="help"><?= e(t('settings.general_list_help')) ?></p>

    <label for="consent_text"><?= e(t('settings.consent_text')) ?></label>
    <textarea id="consent_text" name="consent_text" rows="3" required><?= e($v['consent_text']) ?></textarea>
    <p class="help"><?= e(t('settings.consent_text_help')) ?></p>

    <label for="privacy_url"><?= e(t('settings.privacy_url')) ?></label>
    <input id="privacy_url" name="privacy_url" type="url" value="<?= e($v['privacy_url']) ?>" placeholder="https://">
    <p class="help"><?= e(t('settings.privacy_url_help')) ?></p>

    <label for="privacy_text"><?= e(t('settings.privacy_text')) ?></label>
    <textarea id="privacy_text" name="privacy_text" rows="6" placeholder="<?= e(t('settings.privacy_text_placeholder')) ?>"><?= e($v['privacy_text']) ?></textarea>
  </section>

  <section class="card">
    <h2><?= e(t('settings.section_sending')) ?></h2>
    <div class="grid-3">
      <div>
        <label for="batch_size"><?= e(t('settings.batch_size')) ?></label>
        <input id="batch_size" name="batch_size" type="number" min="1" max="500" value="<?= e($v['batch_size']) ?>">
      </div>
      <div>
        <label for="send_delay_ms"><?= e(t('settings.send_delay_ms')) ?></label>
        <input id="send_delay_ms" name="send_delay_ms" type="number" min="0" max="60000" step="100" value="<?= e($v['send_delay_ms']) ?>">
      </div>
      <div>
        <label for="max_per_hour"><?= e(t('settings.max_per_hour')) ?></label>
        <input id="max_per_hour" name="max_per_hour" type="number" min="0" value="<?= e($v['max_per_hour']) ?>">
      </div>
    </div>
    <p class="help"><?= e(t('settings.sending_help')) ?></p>
  </section>

  <section class="card">
    <h2><?= e(t('settings.section_feeds')) ?></h2>
    <label for="feed_subject"><?= e(t('settings.feed_subject')) ?></label>
    <input id="feed_subject" name="feed_subject" maxlength="200" value="<?= e($v['feed_subject']) ?>">
    <p class="help"><?= e(t('settings.feed_subject_help')) ?></p>
    <label for="feed_max_items"><?= e(t('settings.feed_max_items')) ?></label>
    <input id="feed_max_items" name="feed_max_items" type="number" min="1" max="50" value="<?= e($v['feed_max_items']) ?>">
    <p class="help"><?= e(t('settings.feed_template_help')) ?></p>
  </section>

  <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
</form>
<?php
nm_layout_end();
