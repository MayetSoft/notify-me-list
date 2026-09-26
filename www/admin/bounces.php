<?php
/**
 * Admin: bounce handling — settings, mailbox test/check, bounce log,
 * deactivated subscribers (reactivate or delete).
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

$errors = [];
$result = null;
$transcript = [];
$keys = ['bounce_enabled', 'bounce_return_path', 'bounce_same_as_smtp', 'imap_host', 'imap_port', 'imap_encryption',
    'imap_username', 'imap_folder', 'bounce_action', 'bounce_hard_threshold', 'bounce_soft_threshold'];
$v = [];
foreach ($keys as $k) {
    $v[$k] = (string) setting($k, '');
}
$passwordSet = (string) setting('imap_password', '') !== '';

if (nm_is_post()) {
    csrf_check();
    $action = nm_post('action');
    @set_time_limit(120);
    if ($action === 'reactivate' || $action === 'delete') {
        $id = (int) nm_post('id');
        if ($action === 'reactivate') {
            Bounces::reactivate($id);
            flash('success', t('bounces.reactivated'));
        } else {
            Subscribers::delete($id);
            flash('success', t('dashboard.deleted'));
        }
        nm_redirect(nm_link('admin/bounces.php'));
    }
    if ($action === 'check') {
        try {
            $r = Bounces::processMailbox();
            flash('success', t('bounces.check_result', ['checked' => $r['checked'], 'bounces' => $r['bounces'], 'deactivated' => $r['deactivated']]));
        } catch (Throwable $e) {
            flash('error', t('bounces.check_failed', ['error' => $e->getMessage()]));
        }
        nm_redirect(nm_link('admin/bounces.php'));
    }
    if ($action === 'save' || $action === 'test') {
        foreach ($keys as $k) {
            $v[$k] = nm_post($k);
        }
        $v['bounce_enabled'] = nm_post('bounce_enabled') === '1' ? '1' : '0';
        $v['bounce_same_as_smtp'] = nm_post('bounce_same_as_smtp') === '1' ? '1' : '0';
        $v['bounce_return_path'] = nm_normalize_email($v['bounce_return_path']);
        if (!in_array($v['imap_encryption'], ['ssl', 'tls', 'none'], true)) {
            $v['imap_encryption'] = 'ssl';
        }
        if (!in_array($v['bounce_action'], ['seen', 'delete', 'none'], true)) {
            $v['bounce_action'] = 'seen';
        }
        $port = (int) $v['imap_port'];
        if ($port < 1 || $port > 65535) {
            $errors[] = t('smtp.err_port');
        }
        if ($v['bounce_return_path'] !== '' && !nm_valid_email($v['bounce_return_path'])) {
            $errors[] = t('bounces.err_return_path');
        }
        if ($v['bounce_same_as_smtp'] !== '1' && $v['imap_host'] !== '' && !preg_match('/^[a-z0-9.\-]+$/i', $v['imap_host'])) {
            $errors[] = t('smtp.err_host');
        }
        if (preg_match('/[\r\n"\\\\]/', $v['imap_folder'])) {
            $errors[] = t('bounces.err_folder');
        }
        $v['imap_folder'] = $v['imap_folder'] !== '' ? $v['imap_folder'] : 'INBOX';
        $v['bounce_hard_threshold'] = (string) max(1, min(100, (int) $v['bounce_hard_threshold']));
        $v['bounce_soft_threshold'] = (string) max(1, min(100, (int) $v['bounce_soft_threshold']));
        $newPassword = isset($_POST['imap_password']) && is_string($_POST['imap_password']) ? $_POST['imap_password'] : '';

        if (!$errors && $action === 'test') {
            $cfg = Bounces::imapConfig();
            $cfg['port'] = $port;
            $cfg['encryption'] = $v['imap_encryption'];
            $cfg['folder'] = $v['imap_folder'];
            if ($v['bounce_same_as_smtp'] !== '1') {
                $cfg['host'] = $v['imap_host'];
                $cfg['username'] = $v['imap_username'];
                $cfg['password'] = $newPassword;
                if ($newPassword === '') {
                    try {
                        $cfg['password'] = Crypto::decrypt((string) setting('imap_password', ''));
                    } catch (Throwable $e) {
                        $cfg['password'] = '';
                    }
                }
            }
            try {
                $info = Bounces::testConnection($cfg, $transcript);
                $result = ['success', t('bounces.test_ok', ['folder' => $cfg['folder'], 'n' => $info['exists']])];
            } catch (Throwable $e) {
                $result = ['error', t('bounces.test_failed', ['error' => $e->getMessage()])];
            }
        } elseif (!$errors) {
            foreach ($keys as $k) {
                setting_set($k, $v[$k]);
            }
            setting_set('imap_port', (string) $port);
            if ($newPassword !== '') {
                setting_set('imap_password', Crypto::encrypt($newPassword));
            }
            flash('success', t('common.saved'));
            nm_redirect(nm_link('admin/bounces.php'));
        }
    }
}

$log = db_all('SELECT * FROM bounce_log ORDER BY id DESC LIMIT 50');
$bounced = db_all("SELECT * FROM subscribers WHERE status = 'bounced' ORDER BY last_bounce_at DESC LIMIT 200");
$lastRun = (int) setting('bounce_last_run', 0);
$lastError = (string) setting('bounce_last_error', '');
$smtpUser = (string) setting('smtp_username', '');
$fromEmail = (string) setting('from_email', '') ?: (string) setting('admin_email', '');

nm_layout_start('admin', t('nav.bounces'), 'bounces');
?>
<h1><?= e(t('nav.bounces')) ?></h1>
<p class="muted"><?= e(t('bounces.intro')) ?></p>

<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($result): ?>
  <div class="alert alert-<?= e($result[0]) ?>" role="status"><?= e($result[1]) ?></div>
  <?php if ($transcript): ?>
    <details class="card"><summary><?= e(t('smtp.transcript')) ?></summary><pre class="code"><?= e(implode("\n", $transcript)) ?></pre></details>
  <?php endif; ?>
<?php endif; ?>

<section class="card">
  <h2><?= e(t('bounces.status')) ?></h2>
  <p>
    <?php if (!Bounces::enabled()): ?>
      <span class="badge"><?= e(t('bounces.disabled')) ?></span> <?= e(t('bounces.disabled_help')) ?>
    <?php elseif ($lastError !== ''): ?>
      <span class="badge badge-error"><?= e(t('bounces.error')) ?></span> <?= e($lastError) ?>
    <?php else: ?>
      <span class="badge badge-ok"><?= e(t('bounces.enabled')) ?></span>
      <?= e(t('bounces.last_run', ['date' => nm_format_date($lastRun)])) ?>
    <?php endif; ?>
  </p>
  <?php if (Bounces::enabled()): ?>
    <form method="post" action="<?= e(nm_link('admin/bounces.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="check">
      <button class="btn"><?= e(t('bounces.check_now')) ?></button></form>
  <?php endif; ?>
  <p class="help"><?= e(t('bounces.smtp_note')) ?></p>
</section>

<?php if ($bounced): ?>
<section class="card">
  <h2><?= e(t('bounces.deactivated_title')) ?> <span class="muted">(<?= count($bounced) ?>)</span></h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th><?= e(t('field.email')) ?></th><th><?= e(t('bounces.reason')) ?></th><th><?= e(t('field.date')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($bounced as $s): ?>
      <tr>
        <td><a href="<?= e(nm_link('admin/subscriber.php', ['id' => $s['id']])) ?>"><?= e($s['email']) ?></a></td>
        <td class="small"><?= e($s['last_bounce_reason']) ?></td>
        <td class="small nowrap"><?= e(nm_format_date($s['last_bounce_at'])) ?></td>
        <td class="actions">
          <form method="post" action="<?= e(nm_link('admin/bounces.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reactivate"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-small"><?= e(t('bounces.reactivate')) ?></button></form>
          <form method="post" action="<?= e(nm_link('admin/bounces.php')) ?>" class="inline" data-confirm="<?= e(t('dashboard.delete_confirm', ['email' => $s['email']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-small btn-danger-outline"><?= e(t('common.delete')) ?></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<form method="post" action="<?= e(nm_link('admin/bounces.php')) ?>" class="form">
  <?= csrf_field() ?>
  <section class="card">
    <h2><?= e(t('bounces.settings')) ?></h2>
    <label class="check"><input type="checkbox" name="bounce_enabled" value="1"<?= $v['bounce_enabled'] === '1' ? ' checked' : '' ?>> <span><strong><?= e(t('bounces.enable')) ?></strong></span></label>

    <label for="bounce_return_path"><?= e(t('bounces.return_path')) ?></label>
    <input id="bounce_return_path" name="bounce_return_path" type="email" value="<?= e($v['bounce_return_path']) ?>" placeholder="<?= e($fromEmail) ?>">
    <p class="help"><?= e(t('bounces.return_path_help', ['from' => $fromEmail])) ?></p>

    <label class="check"><input type="checkbox" name="bounce_same_as_smtp" value="1"<?= $v['bounce_same_as_smtp'] === '1' ? ' checked' : '' ?>> <span><?= e(t('bounces.same_as_smtp', ['user' => $smtpUser !== '' ? $smtpUser : '—'])) ?></span></label>
    <p class="help"><?= e(t('bounces.same_as_smtp_help')) ?></p>

    <div class="grid-2">
      <div>
        <label for="imap_host"><?= e(t('bounces.imap_host')) ?></label>
        <input id="imap_host" name="imap_host" value="<?= e($v['imap_host']) ?>" placeholder="<?= e((string) setting('smtp_host', '')) ?>">
      </div>
      <div>
        <label for="imap_username"><?= e(t('smtp.username')) ?></label>
        <input id="imap_username" name="imap_username" value="<?= e($v['imap_username']) ?>" autocomplete="off">
      </div>
      <div>
        <label for="imap_password"><?= e(t('smtp.password')) ?></label>
        <input id="imap_password" name="imap_password" type="password" autocomplete="new-password" placeholder="<?= e($passwordSet ? t('smtp.password_keep') : '') ?>">
      </div>
      <div>
        <label for="imap_folder"><?= e(t('bounces.folder')) ?></label>
        <input id="imap_folder" name="imap_folder" value="<?= e($v['imap_folder']) ?>">
      </div>
      <div>
        <label for="imap_encryption"><?= e(t('smtp.encryption')) ?></label>
        <select id="imap_encryption" name="imap_encryption" data-port-hint="imap_port" data-ports='{"ssl":"993","tls":"143","none":"143"}'>
          <option value="ssl"<?= $v['imap_encryption'] === 'ssl' ? ' selected' : '' ?>><?= e(t('bounces.enc_ssl')) ?></option>
          <option value="tls"<?= $v['imap_encryption'] === 'tls' ? ' selected' : '' ?>><?= e(t('bounces.enc_tls')) ?></option>
          <option value="none"<?= $v['imap_encryption'] === 'none' ? ' selected' : '' ?>><?= e(t('bounces.enc_none')) ?></option>
        </select>
      </div>
      <div>
        <label for="imap_port"><?= e(t('smtp.port')) ?></label>
        <input id="imap_port" name="imap_port" type="number" min="1" max="65535" value="<?= e($v['imap_port']) ?>">
      </div>
    </div>
    <p class="help"><?= e(t('bounces.imap_help')) ?></p>

    <label for="bounce_action"><?= e(t('bounces.action')) ?></label>
    <select id="bounce_action" name="bounce_action">
      <option value="seen"<?= $v['bounce_action'] === 'seen' ? ' selected' : '' ?>><?= e(t('bounces.action_seen')) ?></option>
      <option value="delete"<?= $v['bounce_action'] === 'delete' ? ' selected' : '' ?>><?= e(t('bounces.action_delete')) ?></option>
      <option value="none"<?= $v['bounce_action'] === 'none' ? ' selected' : '' ?>><?= e(t('bounces.action_none')) ?></option>
    </select>
    <p class="help"><?= e(t('bounces.action_help')) ?></p>

    <div class="grid-2">
      <div>
        <label for="bounce_hard_threshold"><?= e(t('bounces.hard_threshold')) ?></label>
        <input id="bounce_hard_threshold" name="bounce_hard_threshold" type="number" min="1" max="100" value="<?= e($v['bounce_hard_threshold']) ?>">
      </div>
      <div>
        <label for="bounce_soft_threshold"><?= e(t('bounces.soft_threshold')) ?></label>
        <input id="bounce_soft_threshold" name="bounce_soft_threshold" type="number" min="1" max="100" value="<?= e($v['bounce_soft_threshold']) ?>">
      </div>
    </div>
    <p class="help"><?= e(t('bounces.threshold_help')) ?></p>

    <div class="button-row">
      <button type="submit" name="action" value="save" class="btn btn-primary"><?= e(t('common.save')) ?></button>
      <button type="submit" name="action" value="test" class="btn"><?= e(t('bounces.test_button')) ?></button>
    </div>
  </section>
</form>

<section class="card">
  <h2><?= e(t('bounces.log_title')) ?></h2>
  <?php if (!$log): ?>
    <p class="muted"><?= e(t('bounces.log_none')) ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th><?= e(t('field.date')) ?></th><th><?= e(t('field.email')) ?></th><th><?= e(t('field.type')) ?></th><th><?= e(t('bounces.reason')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr>
        <td class="small nowrap"><?= e(nm_format_date($l['created_at'])) ?></td>
        <td><?= e($l['email']) ?></td>
        <td><span class="badge <?= $l['kind'] === 'hard' ? 'badge-error' : 'badge-warn' ?>"><?= e(t('bounces.kind_' . $l['kind'])) ?></span> <span class="muted small"><?= e(t('bounces.source_' . $l['source'])) ?></span></td>
        <td class="small"><?= e(trim($l['status_code'] . ' ' . $l['reason'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php
nm_layout_end();
