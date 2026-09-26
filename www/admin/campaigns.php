<?php
/**
 * Admin: campaigns (manual messages and feed digests), live sending progress.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

if (nm_is_post()) {
    csrf_check();
    $cid = (int) nm_post('id');
    $action = nm_post('action');
    if ($action === 'clear_pause') {
        Queue::clearPause();
        nm_log('queue', 'Host-limit pause lifted by the admin');
        flash('success', t('campaign.pause_cleared'));
        nm_redirect(nm_link('admin/campaigns.php', $cid ? ['id' => $cid, 'autostart' => 1] : []));
    }
    if ($action === 'pause') {
        Queue::setStatus($cid, 'paused');
    } elseif ($action === 'resume') {
        Queue::setStatus($cid, 'sending');
    } elseif ($action === 'cancel') {
        Queue::setStatus($cid, 'cancelled');
    } elseif ($action === 'retry') {
        $n = Queue::retryFailed($cid);
        flash('success', t('campaign.retried', ['n' => $n]));
    } elseif ($action === 'delete') {
        Queue::delete($cid);
        flash('success', t('campaign.deleted'));
        nm_redirect(nm_link('admin/campaigns.php'));
    }
    nm_redirect(nm_link('admin/campaigns.php', ['id' => $cid] + ($action === 'resume' || $action === 'retry' ? ['autostart' => 1] : [])));
}

$id = (int) nm_get('id');
$campaign = $id ? db_one('SELECT * FROM campaigns WHERE id = ?', [$id]) : null;

if ($campaign):
    $p = Queue::progress($id);
    $errorsList = db_all("SELECT email, error, updated_at FROM queue WHERE campaign_id = ? AND status = 'failed' ORDER BY updated_at DESC LIMIT 20", [$id]);
    $retrying = (int) db_value("SELECT COUNT(*) FROM queue WHERE campaign_id = ? AND status = 'pending' AND attempts > 0", [$id]);
    nm_layout_start('admin', $campaign['subject'], 'campaigns');
?>
<p><a href="<?= e(nm_link('admin/campaigns.php')) ?>">← <?= e(t('nav.campaigns')) ?></a></p>
<h1><?= e($campaign['subject']) ?></h1>
<?php require NM_ROOT . '/templates/host-pause.php'; ?>
<p class="muted">
  <?= e(t('campaign.kind_' . $campaign['kind'])) ?> · <?= e(Queue::audienceLabel($campaign['audience'])) ?> · <?= e(nm_format_date($campaign['created_at'])) ?>
</p>

<section class="card" id="campaign" data-campaign="<?= (int) $id ?>" data-api="<?= e(nm_link('admin/api.php')) ?>"
         data-autostart="<?= nm_get('autostart') === '1' && $campaign['status'] === 'sending' ? '1' : '0' ?>">
  <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $p['percent'] ?>">
    <div class="progress-bar" style="width:<?= (int) $p['percent'] ?>%"></div>
  </div>
  <p class="progress-text">
    <strong data-field="status_label"><?= e($p['status_label']) ?></strong> —
    <?= e(t('campaign.sent')) ?> <strong data-field="sent"><?= (int) $p['sent'] ?></strong> /
    <span data-field="total"><?= (int) $p['total'] ?></span> ·
    <?= e(t('campaign.pending')) ?> <span data-field="pending"><?= (int) $p['pending'] ?></span> ·
    <?= e(t('campaign.failed')) ?> <span data-field="failed"><?= (int) $p['failed'] ?></span> ·
    <?= e(t('campaign.skipped')) ?> <span data-field="skipped"><?= (int) $p['skipped'] ?></span>
  </p>
  <p class="js-status muted" aria-live="polite"></p>
  <?php if ($retrying): ?><p class="muted small"><?= e(t('campaign.retrying', ['n' => $retrying])) ?></p><?php endif; ?>

  <div class="button-row">
    <?php if ($campaign['status'] === 'sending' && $p['pending'] > 0): ?>
      <button type="button" class="btn btn-primary js-start"><?= e(t('campaign.start')) ?></button>
      <button type="button" class="btn js-stop" hidden><?= e(t('campaign.stop_here')) ?></button>
    <?php endif; ?>
    <?php if ($campaign['status'] === 'sending'): ?>
      <form method="post" action="<?= e(nm_link('admin/campaigns.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="action" value="pause"><button class="btn"><?= e(t('campaign.pause')) ?></button></form>
    <?php endif; ?>
    <?php if ($campaign['status'] === 'paused'): ?>
      <form method="post" action="<?= e(nm_link('admin/campaigns.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="action" value="resume"><button class="btn btn-primary"><?= e(t('campaign.resume')) ?></button></form>
    <?php endif; ?>
    <?php if (in_array($campaign['status'], ['sending', 'paused'], true)): ?>
      <form method="post" action="<?= e(nm_link('admin/campaigns.php')) ?>" class="inline" data-confirm="<?= e(t('campaign.cancel_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-danger-outline"><?= e(t('campaign.cancel')) ?></button></form>
    <?php endif; ?>
    <?php if ($p['failed'] > 0 && $campaign['status'] !== 'cancelled'): ?>
      <form method="post" action="<?= e(nm_link('admin/campaigns.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="action" value="retry"><button class="btn"><?= e(t('campaign.retry')) ?></button></form>
    <?php endif; ?>
    <?php if (in_array($campaign['status'], ['done', 'cancelled'], true)): ?>
      <form method="post" action="<?= e(nm_link('admin/campaigns.php')) ?>" class="inline" data-confirm="<?= e(t('campaign.delete_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-danger-outline"><?= e(t('common.delete')) ?></button></form>
    <?php endif; ?>
  </div>
  <p class="help"><?= e(t('campaign.help_background')) ?></p>
</section>

<?php if ($errorsList): ?>
<section class="card">
  <h2><?= e(t('campaign.errors')) ?></h2>
  <table class="table"><tbody>
  <?php foreach ($errorsList as $er): ?>
    <tr><td><?= e($er['email']) ?></td><td class="small"><?= e($er['error']) ?></td><td class="nowrap small"><?= e(nm_format_date($er['updated_at'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>

<?php if ($campaign['kind'] === 'manual'): ?>
<section class="card">
  <h2><?= e(t('campaign.content')) ?></h2>
  <?php list($prevHtml) = Mailer::wrap($campaign['subject'], $campaign['body_html'], $campaign['body_text'], ['id' => 0, 'email' => (string) setting('admin_email'), 'created_at' => 0], nm_abs_url('unsubscribe.php', ['t' => 'preview'])); ?>
  <iframe class="preview" sandbox="" srcdoc="<?= e($prevHtml) ?>" title="<?= e(t('campaign.content')) ?>"></iframe>
</section>
<?php endif; ?>
<?php
    nm_layout_end();
    exit;
endif;

// --- List ---------------------------------------------------------------------
$perPage = 30;
$total = (int) db_value('SELECT COUNT(*) FROM campaigns');
$pages = max(1, (int) ceil($total / $perPage));
$page = max(1, min($pages, (int) nm_get('page', '1')));
Queue::finalize([]);
$rows = db_all('SELECT * FROM campaigns ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$queued = Queue::remaining();

nm_layout_start('admin', t('nav.campaigns'), 'campaigns');
?>
<h1><?= e(t('nav.campaigns')) ?></h1>
<?php require NM_ROOT . '/templates/host-pause.php'; ?>
<p class="muted"><?= e(t('campaign.list_intro', ['n' => $queued, 'hour' => Queue::sentLastHour(), 'max' => setting_int('max_per_hour', 0, 1000000) ?: '∞', 'day' => Queue::sentLastDay(), 'maxday' => setting_int('max_per_day', 0, 10000000) ?: '∞'])) ?></p>
<?php if (!$rows): ?>
  <p class="muted"><?= e(t('campaign.none')) ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th><?= e(t('field.date')) ?></th><th><?= e(t('field.subject')) ?></th><th><?= e(t('field.type')) ?></th><th><?= e(t('field.status')) ?></th><th class="num"><?= e(t('campaign.sent')) ?></th><th class="num"><?= e(t('campaign.failed')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td class="nowrap small"><?= e(nm_format_date($c['created_at'])) ?></td>
      <td><a href="<?= e(nm_link('admin/campaigns.php', ['id' => $c['id']])) ?>"><?= e($c['subject']) ?></a></td>
      <td class="small"><?= e(t('campaign.kind_' . $c['kind'])) ?></td>
      <td><span class="badge badge-<?= e($c['status']) ?>"><?= e(t('campaign.status_' . $c['status'])) ?></span></td>
      <td class="num"><?= (int) $c['sent'] ?> / <?= (int) $c['total'] ?></td>
      <td class="num"><?= (int) $c['failed'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
  <nav class="pagination">
    <?php if ($page > 1): ?><a href="<?= e(nm_link('admin/campaigns.php', ['page' => $page - 1])) ?>">← <?= e(t('common.previous')) ?></a><?php endif; ?>
    <span><?= e(t('common.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
    <?php if ($page < $pages): ?><a href="<?= e(nm_link('admin/campaigns.php', ['page' => $page + 1])) ?>"><?= e(t('common.next')) ?> →</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
<?php
nm_layout_end();
