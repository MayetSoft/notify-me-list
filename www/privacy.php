<?php
/**
 * Privacy policy placeholder. Edit the text in Admin > Settings, or point the
 * "privacy policy URL" setting to your own page.
 */
require __DIR__ . '/_boot.php';
nm_require_ready();

$custom = trim((string) setting('privacy_text', ''));
nm_layout_start('public', t('privacy.title'));
?>
<h1><?= e(t('privacy.title')) ?></h1>
<div class="card prose">
<?php if ($custom !== ''): ?>
  <?= nm_text_to_html($custom) ?>
<?php else: ?>
  <?= nm_text_to_html(t('privacy.default', [
      'site' => nm_site_name(),
      'email' => (string) setting('admin_email', ''),
      'days' => setting_int('pending_days', 1, 3650),
  ])) ?>
<?php endif; ?>
</div>
<?php
nm_layout_end();
