<?php
/**
 * Admin: cron jobs — status, automatic installation (crontab or cPanel API
 * token), and the exact lines for manual setup in cPanel > Cron Jobs.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

$errors = [];
if (nm_is_post()) {
    csrf_check();
    $action = nm_post('action');
    @set_time_limit(90);
    try {
        if ($action === 'php') {
            $php = nm_post('cron_php');
            if ($php !== '' && !preg_match('~^[A-Za-z0-9_/.\-]+$~', $php)) {
                throw new InvalidArgumentException(t('cron.err_php'));
            }
            setting_set('cron_php', $php);
            flash('success', t('common.saved'));
        } elseif ($action === 'crontab_install') {
            CronSetup::crontabInstall();
            flash('success', t('cron.installed'));
        } elseif ($action === 'crontab_remove') {
            CronSetup::crontabInstall(true);
            flash('success', t('cron.removed'));
        } elseif ($action === 'cpanel') {
            $token = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '';
            $r = CronSetup::cpanelInstall(nm_post('host'), (int) nm_post('port'), nm_post('user'), $token, nm_post('insecure') !== '1');
            flash('success', $r['added'] ? t('cron.cpanel_added', ['n' => count($r['added'])]) : t('cron.cpanel_already'));
        }
        nm_redirect(nm_link('admin/cron.php'));
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$crontab = CronSetup::crontabAvailable();
$crontabStatus = $crontab ? CronSetup::crontabStatus() : null;
$lines = CronSetup::lines();
$last = ['feeds' => (int) setting('cron_last_feeds', 0), 'queue' => (int) setting('cron_last_queue', 0)];
$expected = ['feeds' => 20 * 60, 'queue' => 10 * 60]; // a bit more than the interval
$labels = ['feeds' => t('cron.job_feeds'), 'queue' => t('cron.job_queue')];
$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']) : '';
$user = function_exists('get_current_user') ? (string) get_current_user() : '';

nm_layout_start('admin', t('nav.cron'), 'cron');
?>
<h1><?= e(t('nav.cron')) ?></h1>
<p class="muted"><?= e(t('cron.intro')) ?></p>
<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="card">
  <h2><?= e(t('cron.status')) ?></h2>
  <table class="table">
    <thead><tr><th><?= e(t('cron.job')) ?></th><th><?= e(t('cron.interval')) ?></th><th><?= e(t('cron.last_run')) ?></th></tr></thead>
    <tbody>
    <?php foreach (CronSetup::jobs() as $key => $job): $ok = $last[$key] > nm_now() - $expected[$key]; ?>
      <tr>
        <td><strong><?= e($labels[$key]) ?></strong><br><code class="small"><?= e(basename($job[1])) ?></code></td>
        <td class="small"><?= e(t('cron.every_' . $key)) ?></td>
        <td>
          <?php if ($last[$key]): ?>
            <span class="badge <?= $ok ? 'badge-ok' : 'badge-warn' ?>"><?= e($ok ? t('cron.running') : t('cron.late')) ?></span>
            <span class="small"><?= e(nm_format_date($last[$key])) ?></span>
          <?php else: ?>
            <span class="badge badge-warn"><?= e(t('cron.never')) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="help"><?= e(t('cron.status_help')) ?></p>
</section>

<section class="card">
  <h2><?= e(t('cron.auto_title')) ?></h2>
  <?php if ($crontab): ?>
    <p><?= e(t('cron.crontab_available')) ?></p>
    <ul class="small">
      <?php foreach ($crontabStatus as $key => $line): ?>
        <li><?= e($labels[$key]) ?> : <?= $line ? '<span class="badge badge-ok">' . e(t('cron.present')) . '</span>' : '<span class="badge badge-warn">' . e(t('cron.absent')) . '</span>' ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="button-row">
      <form method="post" action="<?= e(nm_link('admin/cron.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="crontab_install">
        <button class="btn btn-primary"><?= e(in_array(null, $crontabStatus, true) ? t('cron.install_button') : t('cron.reinstall_button')) ?></button></form>
      <?php if (array_filter($crontabStatus)): ?>
      <form method="post" action="<?= e(nm_link('admin/cron.php')) ?>" class="inline" data-confirm="<?= e(t('cron.remove_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="crontab_remove">
        <button class="btn btn-danger-outline"><?= e(t('cron.remove_button')) ?></button></form>
      <?php endif; ?>
    </div>
    <p class="help"><?= e(t('cron.crontab_help')) ?></p>
  <?php else: ?>
    <p class="muted"><?= e(t('cron.crontab_unavailable')) ?></p>
  <?php endif; ?>

  <details<?= $crontab ? '' : ' open' ?>>
    <summary><?= e(t('cron.cpanel_title')) ?></summary>
    <p class="help"><?= e(t('cron.cpanel_intro')) ?></p>
    <form method="post" action="<?= e(nm_link('admin/cron.php')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cpanel">
      <div class="grid-2">
        <div>
          <label for="host"><?= e(t('cron.cpanel_host')) ?></label>
          <input id="host" name="host" value="<?= e($host) ?>" required>
        </div>
        <div>
          <label for="port"><?= e(t('cron.cpanel_port')) ?></label>
          <input id="port" name="port" type="number" value="2083" required>
        </div>
        <div>
          <label for="user"><?= e(t('cron.cpanel_user')) ?></label>
          <input id="user" name="user" value="<?= e($user) ?>" required autocomplete="off">
        </div>
        <div>
          <label for="token"><?= e(t('cron.cpanel_token')) ?></label>
          <input id="token" name="token" type="password" required autocomplete="off">
        </div>
      </div>
      <label class="check"><input type="checkbox" name="insecure" value="1"> <span><?= e(t('cron.cpanel_insecure')) ?></span></label>
      <p class="help"><?= e(t('cron.cpanel_token_help')) ?></p>
      <button type="submit" class="btn btn-primary"><?= e(t('cron.cpanel_button')) ?></button>
    </form>
  </details>
</section>

<section class="card">
  <h2><?= e(t('cron.manual_title')) ?></h2>
  <ol class="steps">
    <li><?= e(t('cron.manual_step1')) ?></li>
    <li><?= e(t('cron.manual_step2')) ?></li>
    <li><?= e(t('cron.manual_step3')) ?>
      <?php foreach (CronSetup::jobs() as $key => $job): ?>
        <p class="small"><strong><?= e($labels[$key]) ?></strong> — <?= e(t('cron.manual_minute', ['minute' => $job[0]])) ?></p>
        <pre class="code"><?= e(CronSetup::command($job[1])) ?></pre>
      <?php endforeach; ?>
    </li>
  </ol>
  <p class="help"><?= e(t('cron.manual_crontab')) ?></p>
  <pre class="code"><?= e(implode("\n", $lines)) ?></pre>
</section>

<section class="card">
  <h2><?= e(t('cron.php_title')) ?></h2>
  <form method="post" action="<?= e(nm_link('admin/cron.php')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="php">
    <label for="cron_php"><?= e(t('cron.php_label')) ?></label>
    <input id="cron_php" name="cron_php" value="<?= e((string) setting('cron_php', '')) ?>" placeholder="<?= e(CronSetup::detectPhp()) ?>">
    <p class="help"><?= e(t('cron.php_help', ['detected' => CronSetup::detectPhp()])) ?></p>
    <button type="submit" class="btn"><?= e(t('common.save')) ?></button>
  </form>
</section>
<?php
nm_layout_end();
