<?php
/**
 * Subscriber self-service ("manage my subscription"), via magic link.
 * Uses the same token mechanism as the admin login (purpose "manage").
 */
require __DIR__ . '/_boot.php';
nm_require_ready();
nm_session_start();

$view = 'request';     // request | sent | landing | invalid | manage | confirm_empty | deleted
$token = '';
$pendingChoice = null; // lists waiting for the "no subscription left" decision

// --- 1. Magic link landing: show a button, consume on POST ------------------
if (nm_get('token') !== '' && !nm_is_post()) {
    $token = nm_get('token');
    $view = Tokens::find($token, 'manage') ? 'landing' : 'invalid';
}

if (nm_is_post()) {
    $action = nm_post('action');

    if ($action === 'login') {
        // Token-authenticated form (no CSRF token by design: the token is the secret).
        $row = Tokens::consume(nm_post('token'), 'manage');
        $sub = $row ? Subscribers::findByEmail($row['email']) : null;
        if ($sub) {
            Tokens::revokeAll('manage', $sub['email']);
            nm_subscriber_login((int) $sub['id']);
            nm_redirect(nm_link('manage.php'));
        }
        $view = 'invalid';
    } else {
        csrf_check();
        if ($action === 'request') {
            $email = nm_normalize_email(nm_post('email'));
            if (!nm_valid_email($email)) {
                flash('error', t('signup.err_email'));
                nm_redirect(nm_link('manage.php'));
            }
            $sub = Subscribers::findByEmail($email);
            if ($sub) {
                Tokens::sendMagicLink('manage', $email, (int) $sub['id']);
            } else {
                // Still count the attempt so the page cannot be used to probe addresses by timing/limits.
                Tokens::allowMailTo($email);
            }
            $view = 'sent';
        }
    }
}

// --- 2. Logged-in subscriber -------------------------------------------------
$subId = nm_subscriber_id();
$sub = $subId ? Subscribers::find($subId) : null;
if ($subId && !$sub) {
    nm_subscriber_logout();
}

if ($sub && nm_is_post() && in_array(nm_post('action'), ['save', 'confirm_empty', 'delete', 'activate', 'logout'], true)) {
    $action = nm_post('action');
    if ($action === 'logout') {
        nm_subscriber_logout();
        flash('success', t('manage.logged_out'));
        nm_redirect(nm_link('manage.php'));
    }
    if ($action === 'delete') {
        Subscribers::delete((int) $sub['id']);
        nm_subscriber_logout();
        $sub = null;
        $view = 'deleted';
    }
    if ($action === 'activate' && $sub['status'] === 'pending') {
        // Following the magic link proved ownership of the address.
        Subscribers::activate((int) $sub['id']);
        flash('success', t('manage.activated'));
        nm_redirect(nm_link('manage.php'));
    }
    if ($action === 'save' || $action === 'confirm_empty') {
        $current = Subscribers::feeds((int) $sub['id']);
        $allowed = array_map('intval', array_column(Feeds::active(), 'id'));
        foreach ($current as $f) {
            $allowed[] = (int) $f['id']; // paused feeds they already follow can be kept
        }
        $general = nm_post('general') === '1';
        $feedIds = array_values(array_intersect(nm_post_ids('feeds'), $allowed));
        $hadSomething = (int) $sub['general_list'] === 1 || $current;

        if (!$general && !$feedIds && $hadSomething && $action === 'save') {
            // Last subscription removed: ask instead of silently deleting the person.
            $view = 'confirm_empty';
        } else {
            if ($action === 'confirm_empty' && nm_post('choice') === 'delete') {
                Subscribers::delete((int) $sub['id']);
                nm_subscriber_logout();
                $sub = null;
                $view = 'deleted';
            } else {
                Subscribers::setLists((int) $sub['id'], $general, $feedIds);
                nm_log('subscribers', 'Subscriber #' . $sub['id'] . ' updated their lists');
                flash('success', t('manage.saved'));
                nm_redirect(nm_link('manage.php'));
            }
        }
    }
}

if ($sub && $view === 'request') {
    $view = 'manage';
}

nm_layout_start('public', t('manage.title'));
?>
<h1><?= e(t('manage.title')) ?></h1>

<?php if ($view === 'request'): ?>
  <p class="lead"><?= e(t('manage.request_intro')) ?></p>
  <form method="post" action="<?= e(nm_link('manage.php')) ?>" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="request">
    <label for="email"><?= e(t('signup.email')) ?></label>
    <input type="email" id="email" name="email" required autocomplete="email" maxlength="254">
    <button type="submit" class="btn btn-primary"><?= e(t('manage.request_button')) ?></button>
  </form>

<?php elseif ($view === 'sent'): ?>
  <div class="alert alert-success"><?= e(t('manage.request_sent', ['minutes' => (int) (Tokens::MAGIC_TTL / 60)])) ?></div>

<?php elseif ($view === 'landing'): ?>
  <p><?= e(t('manage.landing_intro')) ?></p>
  <form method="post" action="<?= e(nm_link('manage.php')) ?>" class="card">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <button type="submit" class="btn btn-primary"><?= e(t('manage.landing_button')) ?></button>
  </form>

<?php elseif ($view === 'invalid'): ?>
  <div class="alert alert-error"><?= e(t('magic.invalid')) ?></div>
  <p><a href="<?= e(nm_link('manage.php')) ?>"><?= e(t('magic.request_new')) ?></a></p>

<?php elseif ($view === 'deleted'): ?>
  <div class="alert alert-success"><?= e(t('manage.deleted')) ?></div>
  <p><a href="<?= e(nm_link('index.php')) ?>"><?= e(t('unsub.resubscribe')) ?></a></p>

<?php elseif ($view === 'confirm_empty'): ?>
  <div class="card">
    <p><strong><?= e(t('manage.empty_title')) ?></strong></p>
    <p><?= e(t('manage.empty_body')) ?></p>
    <form method="post" action="<?= e(nm_link('manage.php')) ?>" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="confirm_empty">
      <button type="submit" name="choice" value="delete" class="btn btn-danger"><?= e(t('manage.empty_delete')) ?></button>
      <button type="submit" name="choice" value="keep" class="btn"><?= e(t('manage.empty_keep')) ?></button>
      <a href="<?= e(nm_link('manage.php')) ?>" class="btn btn-link"><?= e(t('common.cancel')) ?></a>
    </form>
  </div>

<?php elseif ($view === 'manage' && $sub):
    $myFeeds = Subscribers::feeds((int) $sub['id']);
    $myFeedIds = array_map('intval', array_column($myFeeds, 'id'));
    $choices = [];
    foreach (Feeds::active() as $f) {
        $choices[(int) $f['id']] = ['name' => $f['name'], 'paused' => false];
    }
    foreach ($myFeeds as $f) {
        if (!isset($choices[(int) $f['id']])) {
            $choices[(int) $f['id']] = ['name' => $f['name'], 'paused' => true];
        }
    }
?>
  <?php if ($sub['status'] === 'pending'): ?>
    <div class="alert alert-warning">
      <?= e(t('manage.pending_notice')) ?>
      <form method="post" action="<?= e(nm_link('manage.php')) ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="activate">
        <button type="submit" class="btn btn-small btn-primary"><?= e(t('manage.activate_button')) ?></button>
      </form>
    </div>
  <?php endif; ?>

  <section class="card">
    <h2><?= e(t('manage.my_lists')) ?></h2>
    <form method="post" action="<?= e(nm_link('manage.php')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <label class="check">
        <input type="checkbox" name="general" value="1"<?= (int) $sub['general_list'] === 1 ? ' checked' : '' ?>>
        <span><?= e(nm_general_label()) ?></span>
      </label>
      <?php foreach ($choices as $id => $c): ?>
        <label class="check">
          <input type="checkbox" name="feeds[]" value="<?= (int) $id ?>"<?= in_array($id, $myFeedIds, true) ? ' checked' : '' ?>>
          <span><?= e($c['name']) ?><?= $c['paused'] ? ' <em class="muted">(' . e(t('manage.feed_paused')) . ')</em>' : '' ?></span>
        </label>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>
  </section>

  <section class="card">
    <h2><?= e(t('manage.my_data')) ?></h2>
    <dl class="data">
      <dt><?= e(t('field.email')) ?></dt><dd><?= e($sub['email']) ?></dd>
      <dt><?= e(t('field.status')) ?></dt><dd><?= e(t('status.' . $sub['status'])) ?></dd>
      <dt><?= e(t('field.signup_date')) ?></dt><dd><?= e(nm_format_date($sub['created_at'])) ?></dd>
      <dt><?= e(t('field.confirmed_date')) ?></dt><dd><?= e(nm_format_date($sub['confirmed_at'])) ?></dd>
      <dt><?= e(t('field.ip')) ?></dt><dd><?= e($sub['ip'] !== '' ? $sub['ip'] : '—') ?></dd>
      <dt><?= e(t('field.source')) ?></dt><dd><?= e(t('source.' . $sub['source'])) ?></dd>
      <dt><?= e(t('field.consent_text')) ?></dt><dd><?= e($sub['consent_text']) ?></dd>
      <dt><?= e(t('field.signup_lists')) ?></dt><dd><?= e($sub['signup_lists'] !== '' ? $sub['signup_lists'] : '—') ?></dd>
    </dl>
  </section>

  <section class="card danger-zone">
    <h2><?= e(t('manage.delete_title')) ?></h2>
    <p><?= e(t('manage.delete_body')) ?></p>
    <form method="post" action="<?= e(nm_link('manage.php')) ?>" data-confirm="<?= e(t('manage.delete_confirm')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="btn btn-danger"><?= e(t('manage.delete_button')) ?></button>
    </form>
  </section>

  <form method="post" action="<?= e(nm_link('manage.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="btn btn-link"><?= e(t('nav.logout')) ?></button>
  </form>
<?php endif; ?>
<?php
nm_layout_end();
