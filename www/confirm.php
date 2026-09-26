<?php
/**
 * Double opt-in confirmation link.
 * GET shows a button, POST confirms: automatic link scanners used by some
 * mail providers "click" every link, they must not confirm on their own.
 */
require __DIR__ . '/_boot.php';
nm_require_ready();

$token = nm_is_post() ? nm_post('token') : nm_get('token');
$row = Tokens::find($token, 'confirm');
$sub = $row && $row['subscriber_id'] ? Subscribers::find((int) $row['subscriber_id']) : null;
$confirmed = false;

if ($row && $sub && nm_is_post()) {
    if (Tokens::consume($token, 'confirm')) {
        Subscribers::activate((int) $sub['id']);
        $sub = Subscribers::find((int) $sub['id']);
        $confirmed = true;
        nm_log('subscribers', 'Subscriber #' . $sub['id'] . ' confirmed (double opt-in)');
    } else {
        $row = null;
    }
}

nm_layout_start('public', t('confirm.title'));
?>
<h1><?= e(t('confirm.title')) ?></h1>
<?php if ($confirmed): ?>
  <div class="alert alert-success"><?= e(t('confirm.done')) ?></div>
  <?php $lists = Subscribers::listNames($sub); if ($lists): ?>
    <p><?= e(t('mail.your_lists')) ?></p>
    <ul><?php foreach ($lists as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <p><a href="<?= e(nm_link('manage.php')) ?>"><?= e(t('nav.manage')) ?></a></p>
<?php elseif (!$row || !$sub): ?>
  <div class="alert alert-error"><?= e(t('confirm.invalid')) ?></div>
  <p><a href="<?= e(nm_link('index.php')) ?>"><?= e(t('confirm.signup_again')) ?></a></p>
<?php elseif ($sub['status'] === 'active'): ?>
  <div class="alert alert-success"><?= e(t('confirm.already')) ?></div>
<?php else: ?>
  <p><?= e(t('confirm.intro', ['email' => $sub['email']])) ?></p>
  <form method="post" action="<?= e(nm_link('confirm.php')) ?>" class="card">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <button type="submit" class="btn btn-primary"><?= e(t('confirm.button')) ?></button>
  </form>
<?php endif; ?>
<?php
nm_layout_end();
