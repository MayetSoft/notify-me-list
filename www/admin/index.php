<?php
/**
 * Admin dashboard: counts, health warnings, paginated/searchable subscriber list.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

if (nm_is_post() && nm_post('action') === 'delete') {
    csrf_check();
    $id = (int) nm_post('id');
    Subscribers::delete($id);
    flash('success', t('dashboard.deleted'));
    nm_redirect(nm_link('admin/index.php', array_filter(['q' => nm_post('q'), 'status' => nm_post('status'), 'list' => nm_post('list'), 'page' => nm_post('page')])));
}

$stats = Subscribers::stats();
$feeds = Feeds::all();
$q = mb_substr(nm_get('q'), 0, 100);
$status = in_array(nm_get('status'), ['active', 'pending'], true) ? nm_get('status') : '';
$list = nm_get('list');
$perPage = 50;
$total = Subscribers::countFiltered($q, $status, $list);
$pages = max(1, (int) ceil($total / $perPage));
$page = max(1, min($pages, (int) nm_get('page', '1')));
$rows = Subscribers::listFiltered($q, $status, $list, $perPage, ($page - 1) * $perPage);

// Health checks shown at the top.
$warnings = [];
if (!Mailer::isConfigured()) {
    $warnings[] = ['error', t('dashboard.warn_smtp'), 'admin/smtp.php'];
}
if (is_file(NM_WEB_DIR . '/install.php')) {
    $warnings[] = ['warning', t('dashboard.warn_install'), ''];
}
$lastQueue = (int) setting('cron_last_queue', 0);
$lastFeeds = (int) setting('cron_last_feeds', 0);
$queued = Queue::remaining();
if ($feeds && $lastFeeds < nm_now() - 3 * 3600) {
    $warnings[] = ['warning', $lastFeeds ? t('dashboard.warn_cron_feeds_old', ['date' => nm_format_date($lastFeeds)]) : t('dashboard.warn_cron_feeds_never'), ''];
}
if ($queued > 0 && $lastQueue < nm_now() - 3600) {
    $warnings[] = ['warning', t('dashboard.warn_cron_queue', ['n' => $queued]), 'admin/campaigns.php'];
}
$failingFeeds = array_filter($feeds, function ($f) {
    return (int) $f['error_count'] >= 3 && (int) $f['active'] === 1;
});
if ($failingFeeds) {
    $warnings[] = ['warning', t('dashboard.warn_feeds_failing', ['n' => count($failingFeeds)]), 'admin/feeds.php'];
}

$baseQuery = array_filter(['q' => $q, 'status' => $status, 'list' => $list]);
nm_layout_start('admin', t('nav.dashboard'), 'dashboard');
?>
<h1><?= e(t('nav.dashboard')) ?></h1>

<?php foreach ($warnings as $w): ?>
  <div class="alert alert-<?= e($w[0]) ?>"><?= e($w[1]) ?>
    <?php if ($w[2] !== ''): ?> <a href="<?= e(nm_link($w[2])) ?>"><?= e(t('common.fix')) ?> →</a><?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="stats">
  <div class="stat"><span class="stat-value"><?= (int) $stats['active'] ?></span><span class="stat-label"><?= e(t('dashboard.active')) ?></span></div>
  <div class="stat"><span class="stat-value"><?= (int) $stats['general'] ?></span><span class="stat-label"><?= e(nm_general_label()) ?></span></div>
  <div class="stat"><span class="stat-value"><?= (int) $stats['pending'] ?></span><span class="stat-label"><?= e(t('dashboard.pending')) ?></span></div>
  <div class="stat"><span class="stat-value"><?= (int) $stats['last7'] ?></span><span class="stat-label"><?= e(t('dashboard.last7')) ?></span></div>
  <div class="stat"><span class="stat-value"><?= (int) $queued ?></span><span class="stat-label"><?= e(t('dashboard.queued')) ?></span></div>
</div>

<?php if ($feeds): ?>
<section class="card">
  <h2><?= e(t('dashboard.per_feed')) ?></h2>
  <table class="table">
    <thead><tr><th><?= e(t('field.feed')) ?></th><th class="num"><?= e(t('field.subscribers')) ?></th><th><?= e(t('field.status')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($feeds as $f): ?>
      <tr>
        <td><a href="<?= e(nm_link('admin/index.php', ['list' => 'feed:' . $f['id']])) ?>"><?= e($f['name']) ?></a></td>
        <td class="num"><?= (int) $f['subscriber_count'] ?></td>
        <td><?= (int) $f['active'] ? '<span class="badge badge-ok">' . e(t('feed.active')) . '</span>' : '<span class="badge">' . e(t('feed.paused')) . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="card">
  <h2><?= e(t('dashboard.subscribers')) ?> <span class="muted">(<?= (int) $total ?>)</span></h2>
  <form method="get" action="<?= e(nm_link('admin/index.php')) ?>" class="filters">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('dashboard.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
    <select name="status" aria-label="<?= e(t('field.status')) ?>">
      <option value=""><?= e(t('dashboard.all_statuses')) ?></option>
      <option value="active"<?= $status === 'active' ? ' selected' : '' ?>><?= e(t('status.active')) ?></option>
      <option value="pending"<?= $status === 'pending' ? ' selected' : '' ?>><?= e(t('status.pending')) ?></option>
    </select>
    <select name="list" aria-label="<?= e(t('field.lists')) ?>">
      <option value=""><?= e(t('dashboard.all_lists')) ?></option>
      <option value="general"<?= $list === 'general' ? ' selected' : '' ?>><?= e(nm_general_label()) ?></option>
      <?php foreach ($feeds as $f): ?>
        <option value="feed:<?= (int) $f['id'] ?>"<?= $list === 'feed:' . $f['id'] ? ' selected' : '' ?>><?= e($f['name']) ?></option>
      <?php endforeach; ?>
      <option value="none"<?= $list === 'none' ? ' selected' : '' ?>><?= e(t('dashboard.no_list')) ?></option>
    </select>
    <button type="submit" class="btn"><?= e(t('common.search')) ?></button>
    <?php if ($baseQuery): ?><a class="btn btn-link" href="<?= e(nm_link('admin/index.php')) ?>"><?= e(t('common.reset')) ?></a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <p class="muted"><?= e(t('dashboard.none')) ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr>
      <th><?= e(t('field.email')) ?></th><th><?= e(t('field.status')) ?></th><th><?= e(t('field.signup_date')) ?></th>
      <th><?= e(t('field.lists')) ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $names = nm_describe_lists((int) $r['general_list'] === 1, $r['feed_names']); ?>
      <tr>
        <td><a href="<?= e(nm_link('admin/subscriber.php', ['id' => $r['id']])) ?>"><?= e($r['email']) ?></a></td>
        <td><span class="badge <?= $r['status'] === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= e(t('status.' . $r['status'])) ?></span></td>
        <td class="nowrap"><?= e(nm_format_date($r['created_at'])) ?></td>
        <td class="small"><?= $names ? e(implode(', ', $names)) : '<span class="muted">' . e(t('dashboard.no_list')) . '</span>' ?></td>
        <td class="actions">
          <form method="post" action="<?= e(nm_link('admin/index.php')) ?>" data-confirm="<?= e(t('dashboard.delete_confirm', ['email' => $r['email']])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
            <input type="hidden" name="list" value="<?= e($list) ?>"><input type="hidden" name="page" value="<?= (int) $page ?>">
            <button type="submit" class="btn btn-small btn-danger-outline"><?= e(t('common.delete')) ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
      <?php if ($page > 1): ?><a href="<?= e(nm_link('admin/index.php', $baseQuery + ['page' => $page - 1])) ?>">← <?= e(t('common.previous')) ?></a><?php endif; ?>
      <span><?= e(t('common.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
      <?php if ($page < $pages): ?><a href="<?= e(nm_link('admin/index.php', $baseQuery + ['page' => $page + 1])) ?>"><?= e(t('common.next')) ?> →</a><?php endif; ?>
    </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php
nm_layout_end();
