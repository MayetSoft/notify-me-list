<?php
/**
 * SMTP form fields, shared by install.php and admin/smtp.php.
 * Expects $v (array of current values). $passwordSet (bool, optional): a
 * password is already stored — the field is left empty and keeps it.
 */
$passwordSet = isset($passwordSet) ? $passwordSet : false;
?>
<div class="grid-2">
  <div>
    <label for="smtp_host"><?= e(t('smtp.host')) ?></label>
    <input id="smtp_host" name="smtp_host" value="<?= e($v['smtp_host']) ?>" placeholder="mail.example.com" autocomplete="off">
  </div>
  <div>
    <label for="smtp_port"><?= e(t('smtp.port')) ?></label>
    <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?= e($v['smtp_port']) ?>">
  </div>
</div>
<label for="smtp_encryption"><?= e(t('smtp.encryption')) ?></label>
<select id="smtp_encryption" name="smtp_encryption" data-port-hint="smtp_port">
  <option value="tls"<?= $v['smtp_encryption'] === 'tls' ? ' selected' : '' ?>><?= e(t('smtp.enc_tls')) ?></option>
  <option value="ssl"<?= $v['smtp_encryption'] === 'ssl' ? ' selected' : '' ?>><?= e(t('smtp.enc_ssl')) ?></option>
  <option value="none"<?= $v['smtp_encryption'] === 'none' ? ' selected' : '' ?>><?= e(t('smtp.enc_none')) ?></option>
</select>
<div class="grid-2">
  <div>
    <label for="smtp_username"><?= e(t('smtp.username')) ?></label>
    <input id="smtp_username" name="smtp_username" value="<?= e($v['smtp_username']) ?>" autocomplete="off">
  </div>
  <div>
    <label for="smtp_password"><?= e(t('smtp.password')) ?></label>
    <input id="smtp_password" name="smtp_password" type="password" value="" autocomplete="new-password"
           placeholder="<?= e($passwordSet ? t('smtp.password_keep') : '') ?>">
  </div>
</div>
<?php if ($passwordSet): ?>
  <label class="check"><input type="checkbox" name="smtp_password_clear" value="1"> <span><?= e(t('smtp.password_clear')) ?></span></label>
<?php endif; ?>
<p class="help"><?= e(t('smtp.password_help')) ?></p>
<div class="grid-2">
  <div>
    <label for="from_name"><?= e(t('smtp.from_name')) ?></label>
    <input id="from_name" name="from_name" value="<?= e($v['from_name']) ?>" maxlength="120">
  </div>
  <div>
    <label for="from_email"><?= e(t('smtp.from_email')) ?></label>
    <input id="from_email" name="from_email" type="email" value="<?= e($v['from_email']) ?>">
  </div>
</div>
<p class="help"><?= e(t('smtp.from_help')) ?></p>
<label class="check"><input type="checkbox" name="smtp_verify_cert" value="1"<?= $v['smtp_verify_cert'] === '1' ? ' checked' : '' ?>> <span><?= e(t('smtp.verify_cert')) ?></span></label>
<p class="help"><?= e(t('smtp.verify_cert_help')) ?></p>
