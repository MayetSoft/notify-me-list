<?php
/**
 * One-click unsubscribe, personalised link present in every e-mail.
 *
 * - GET  (the person clicks the link): immediate removal of the subscriber
 *   and all its feed links, then a confirmation page. If the admin enabled
 *   "unsubscribe_confirm", a single confirmation button is shown first
 *   (protects against mail security scanners that open every link).
 * - POST (RFC 8058 "List-Unsubscribe=One-Click" sent by Gmail, Yahoo, Apple
 *   Mail...): immediate removal, plain-text answer.
 * No login and no CSRF token: the signed link itself is the authorisation.
 */
require __DIR__ . '/_boot.php';
nm_require_ready();

$token = nm_get('t');
if ($token === '' && nm_is_post()) {
    $token = nm_post('t');
}
$sub = $token !== '' ? Tokens::subscriberFromUnsubscribeToken($token) : null;

// Mail-client one-click POST (no page to show).
if (nm_is_post() && nm_post('List-Unsubscribe') === 'One-Click') {
    if ($sub) {
        Subscribers::delete((int) $sub['id']);
        nm_log('subscribers', 'One-click (RFC 8058) unsubscribe #' . $sub['id']);
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OK';
    exit;
}

$askFirst = setting('unsubscribe_confirm', '0') === '1' && !nm_is_post();
$done = false;
if ($sub && !$askFirst) {
    Subscribers::delete((int) $sub['id']);
    nm_log('subscribers', 'Unsubscribe link used #' . $sub['id']);
    $done = true;
}

nm_layout_start('public', t('unsub.title'));
?>
<h1><?= e(t('unsub.title')) ?></h1>
<?php if ($done): ?>
  <div class="alert alert-success"><?= e(t('unsub.done', ['email' => $sub['email']])) ?></div>
  <p><?= e(t('unsub.done_details')) ?></p>
  <p><a href="<?= e(nm_link('index.php')) ?>"><?= e(t('unsub.resubscribe')) ?></a></p>
<?php elseif ($sub): ?>
  <p><?= e(t('unsub.confirm_question', ['email' => $sub['email']])) ?></p>
  <form method="post" action="<?= e(nm_link('unsubscribe.php', ['t' => $token])) ?>" class="card">
    <button type="submit" class="btn btn-danger"><?= e(t('unsub.confirm_button')) ?></button>
  </form>
  <p class="muted"><?= e(t('unsub.partial_hint')) ?> <a href="<?= e(nm_link('manage.php')) ?>"><?= e(t('nav.manage')) ?></a></p>
<?php else: ?>
  <div class="alert alert-info"><?= e(t('unsub.invalid')) ?></div>
  <p><a href="<?= e(nm_link('manage.php')) ?>"><?= e(t('nav.manage')) ?></a></p>
<?php endif; ?>
<?php
nm_layout_end();
