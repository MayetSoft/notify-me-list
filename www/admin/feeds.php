<?php
/**
 * Admin: RSS/Atom feeds — add, edit, pause, remove, check now.
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

$errors = [];
$form = ['id' => 0, 'name' => '', 'url' => '', 'frequency' => 60, 'to_general' => 0];

if (nm_is_post()) {
    csrf_check();
    $action = nm_post('action');
    $id = (int) nm_post('id');
    @set_time_limit(120);
    try {
        if ($action === 'save') {
            $form = [
                'id' => $id,
                'name' => nm_post('name'),
                'url' => nm_post('url'),
                'frequency' => (int) nm_post('frequency'),
                'to_general' => nm_post('to_general') === '1' ? 1 : 0,
            ];
            if ($id > 0) {
                Feeds::update($id, $form['name'], $form['url'], $form['frequency'], (bool) $form['to_general']);
                flash('success', t('feed.updated'));
            } else {
                $r = Feeds::create($form['name'], $form['url'], $form['frequency'], (bool) $form['to_general']);
                flash('success', t('feed.created', ['n' => $r['items']]));
            }
            nm_redirect(nm_link('admin/feeds.php'));
        } elseif ($action === 'pause' || $action === 'resume') {
            Feeds::setActive($id, $action === 'resume');
            flash('success', $action === 'resume' ? t('feed.resumed') : t('feed.paused_msg'));
            nm_redirect(nm_link('admin/feeds.php'));
        } elseif ($action === 'delete') {
            Feeds::delete($id);
            flash('success', t('feed.deleted'));
            nm_redirect(nm_link('admin/feeds.php'));
        } elseif ($action === 'check') {
            $report = Feeds::checkDue(true, $id);
            if ($report['errors'] > 0) {
                flash('error', implode(' — ', $report['lines']));
            } else {
                flash('success', implode(' — ', $report['lines']) . ($report['campaign_id'] ? ' ' . t('feed.check_queued') : ''));
            }
            nm_redirect(nm_link('admin/feeds.php'));
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

if (!$errors && nm_get('edit') !== '') {
    $f = Feeds::find((int) nm_get('edit'));
    if ($f) {
        $form = ['id' => (int) $f['id'], 'name' => $f['name'], 'url' => $f['url'], 'frequency' => (int) $f['frequency'], 'to_general' => (int) $f['to_general']];
    }
}

$feeds = Feeds::all();
nm_layout_start('admin', t('nav.feeds'), 'feeds');
?>
<h1><?= e(t('nav.feeds')) ?></h1>
<p class="muted"><?= e(t('feed.intro')) ?></p>

<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($feeds): ?>
<div class="table-wrap">
<table class="table">
  <thead><tr>
    <th><?= e(t('field.name')) ?></th><th><?= e(t('feed.frequency')) ?></th><th class="num"><?= e(t('field.subscribers')) ?></th>
    <th><?= e(t('feed.last_check')) ?></th><th><?= e(t('field.status')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($feeds as $f): ?>
    <tr>
      <td>
        <strong><?= e($f['name']) ?></strong><br>
        <a class="small muted break" href="<?= e(nm_safe_url($f['url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($f['url']) ?></a>
        <?php if ((int) $f['to_general'] === 1): ?><br><span class="badge"><?= e(t('feed.to_general_badge')) ?></span><?php endif; ?>
      </td>
      <td class="small"><?= e(Feeds::frequencyLabel((int) $f['frequency'])) ?></td>
      <td class="num"><?= (int) $f['subscriber_count'] ?></td>
      <td class="small">
        <?= e(nm_format_date($f['last_checked_at'])) ?>
        <?php if ($f['last_error'] !== ''): ?>
          <br><span class="text-error" title="<?= e($f['last_error']) ?>"><?= e(t('feed.error_count', ['n' => (int) $f['error_count']])) ?>: <?= e(mb_substr($f['last_error'], 0, 120)) ?></span>
        <?php elseif ($f['last_success_at']): ?>
          <br><span class="muted"><?= e(t('feed.items_known', ['n' => (int) $f['item_count']])) ?></span>
        <?php endif; ?>
      </td>
      <td><?= (int) $f['active'] ? '<span class="badge badge-ok">' . e(t('feed.active')) . '</span>' : '<span class="badge">' . e(t('feed.paused')) . '</span>' ?></td>
      <td class="actions">
        <a class="btn btn-small" href="<?= e(nm_link('admin/feeds.php', ['edit' => $f['id']])) ?>#feed-form"><?= e(t('common.edit')) ?></a>
        <form method="post" action="<?= e(nm_link('admin/feeds.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="action" value="check"><button class="btn btn-small"><?= e(t('feed.check_now')) ?></button></form>
        <form method="post" action="<?= e(nm_link('admin/feeds.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="action" value="<?= (int) $f['active'] ? 'pause' : 'resume' ?>"><button class="btn btn-small"><?= e((int) $f['active'] ? t('feed.pause') : t('feed.resume')) ?></button></form>
        <form method="post" action="<?= e(nm_link('admin/feeds.php')) ?>" class="inline" data-confirm="<?= e(t('feed.delete_confirm', ['name' => $f['name'], 'n' => (int) $f['subscriber_count']])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-small btn-danger-outline"><?= e(t('common.delete')) ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
  <p class="muted"><?= e(t('feed.none')) ?></p>
<?php endif; ?>

<section class="card" id="feed-form">
  <h2><?= e($form['id'] ? t('feed.edit_title') : t('feed.add_title')) ?></h2>
  <form method="post" action="<?= e(nm_link('admin/feeds.php')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
    <label for="name"><?= e(t('feed.name')) ?></label>
    <input id="name" name="name" required maxlength="120" value="<?= e($form['name']) ?>">
    <p class="help"><?= e(t('feed.name_help')) ?></p>

    <label for="url"><?= e(t('feed.url')) ?></label>
    <input id="url" name="url" type="url" required value="<?= e($form['url']) ?>" placeholder="https://example.com/feed.xml">
    <p class="help"><?= e(t('feed.url_help')) ?></p>

    <label for="frequency"><?= e(t('feed.frequency')) ?></label>
    <select id="frequency" name="frequency">
      <?php foreach (Feeds::FREQUENCIES as $m): ?>
        <option value="<?= (int) $m ?>"<?= (int) $form['frequency'] === $m ? ' selected' : '' ?>><?= e(Feeds::frequencyLabel($m)) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="help"><?= e(t('feed.frequency_help')) ?></p>

    <label class="check"><input type="checkbox" name="to_general" value="1"<?= $form['to_general'] ? ' checked' : '' ?>> <span><?= e(t('feed.to_general', ['list' => nm_general_label()])) ?></span></label>
    <p class="help"><?= e(t('feed.to_general_help')) ?></p>

    <div class="button-row">
      <button type="submit" class="btn btn-primary"><?= e($form['id'] ? t('common.save') : t('feed.add_button')) ?></button>
      <?php if ($form['id']): ?><a class="btn btn-link" href="<?= e(nm_link('admin/feeds.php')) ?>"><?= e(t('common.cancel')) ?></a><?php endif; ?>
    </div>
  </form>
</section>
<?php
nm_layout_end();
