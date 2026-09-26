<?php
/**
 * Admin: one subscriber — stored data, list memberships, deletion.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

$sub = Subscribers::find((int) (nm_is_post() ? nm_post('id') : nm_get('id')));
if (!$sub) {
    flash('error', t('subscriber.not_found'));
    nm_redirect(nm_link('admin/index.php'));
}

if (nm_is_post()) {
    csrf_check();
    $action = nm_post('action');
    if ($action === 'save') {
        Subscribers::setLists((int) $sub['id'], nm_post('general') === '1', Subscribers::filterFeedIds(nm_post_ids('feeds'), false));
        nm_log('subscribers', 'Admin updated lists of subscriber #' . $sub['id']);
        flash('success', t('common.saved'));
        nm_redirect(nm_link('admin/subscriber.php', ['id' => $sub['id']]));
    }
    if ($action === 'delete') {
        Subscribers::delete((int) $sub['id']);
        flash('success', t('dashboard.deleted'));
        nm_redirect(nm_link('admin/index.php'));
    }
}

$myFeedIds = Subscribers::feedIds((int) $sub['id']);
$allFeeds = Feeds::all();
nm_layout_start('admin', $sub['email'], 'dashboard');
?>
<p><a href="<?= e(nm_link('admin/index.php')) ?>">← <?= e(t('nav.dashboard')) ?></a></p>
<h1><?= e($sub['email']) ?></h1>

<section class="card">
  <h2><?= e(t('subscriber.data')) ?></h2>
  <dl class="data">
    <dt><?= e(t('field.status')) ?></dt><dd><?= e(t('status.' . $sub['status'])) ?></dd>
    <dt><?= e(t('field.signup_date')) ?></dt><dd><?= e(nm_format_date($sub['created_at'])) ?></dd>
    <dt><?= e(t('field.confirmed_date')) ?></dt><dd><?= e(nm_format_date($sub['confirmed_at'])) ?></dd>
    <dt><?= e(t('field.ip')) ?></dt><dd><?= e($sub['ip'] !== '' ? $sub['ip'] : '—') ?></dd>
    <dt><?= e(t('field.source')) ?></dt><dd><?= e(t('source.' . $sub['source'])) ?></dd>
    <dt><?= e(t('field.consent_text')) ?></dt><dd><?= e($sub['consent_text']) ?></dd>
    <dt><?= e(t('field.signup_lists')) ?></dt><dd><?= e($sub['signup_lists'] !== '' ? $sub['signup_lists'] : '—') ?></dd>
  </dl>
</section>

<section class="card">
  <h2><?= e(t('field.lists')) ?></h2>
  <form method="post" action="<?= e(nm_link('admin/subscriber.php')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) $sub['id'] ?>">
    <label class="check"><input type="checkbox" name="general" value="1"<?= (int) $sub['general_list'] === 1 ? ' checked' : '' ?>> <span><?= e(nm_general_label()) ?></span></label>
    <?php foreach ($allFeeds as $f): ?>
      <label class="check"><input type="checkbox" name="feeds[]" value="<?= (int) $f['id'] ?>"<?= in_array((int) $f['id'], $myFeedIds, true) ? ' checked' : '' ?>>
        <span><?= e($f['name']) ?><?= (int) $f['active'] ? '' : ' <em class="muted">(' . e(t('feed.paused')) . ')</em>' ?></span></label>
    <?php endforeach; ?>
    <p class="help"><?= e(t('subscriber.lists_help')) ?></p>
    <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>
</section>

<section class="card danger-zone">
  <h2><?= e(t('subscriber.delete_title')) ?></h2>
  <form method="post" action="<?= e(nm_link('admin/subscriber.php')) ?>" data-confirm="<?= e(t('dashboard.delete_confirm', ['email' => $sub['email']])) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int) $sub['id'] ?>">
    <button type="submit" class="btn btn-danger"><?= e(t('subscriber.delete_button')) ?></button>
  </form>
</section>
<?php
nm_layout_end();
