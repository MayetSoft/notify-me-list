<?php
/**
 * Public signup form.
 */
require __DIR__ . '/_boot.php';
nm_require_ready();
nm_session_start();

$feeds = Feeds::active();
$errors = [];
$success = null;
$old = ['email' => '', 'general' => false, 'feeds' => []];

if (nm_is_post()) {
    csrf_check();
    $email = nm_normalize_email(nm_post('email'));
    $consent = nm_post('consent') === '1';
    // Without feeds, the general list is the only (implicit) choice.
    $general = $feeds ? nm_post('general') === '1' : true;
    $feedIds = nm_post_ids('feeds');
    $old = ['email' => $email, 'general' => $general, 'feeds' => $feedIds];

    if (nm_post('website') !== '' || !Tokens::checkFormStamp(nm_post('stamp'))) {
        // Honeypot filled or form submitted too fast: very likely a bot. Pretend it worked.
        nm_log('security', 'Signup rejected as bot from ' . nm_client_ip());
        $success = ['type' => 'success', 'message' => t('signup.success_generic')];
    } else {
        if (!nm_valid_email($email)) {
            $errors[] = t('signup.err_email');
        }
        if (!$consent) {
            $errors[] = t('signup.err_consent');
        }
        if (!$general && !Subscribers::filterFeedIds($feedIds, true)) {
            $errors[] = t('signup.err_no_list');
        }
        if (!$errors && !nm_rate_limit('signup:' . nm_client_ip(), 10, 3600)) {
            $errors[] = t('err.too_many_requests');
        }
        if (!$errors) {
            $r = Subscribers::signup($email, $general, $feedIds, nm_client_ip());
            $double = setting('optin_mode', 'single') === 'double';
            if ($r['result'] === 'throttled') {
                $success = ['type' => 'warning', 'message' => t('signup.success_throttled')];
            } elseif ($r['result'] === 'exists') {
                // Same wording as a new signup: never reveal who is subscribed.
                $success = ['type' => 'success', 'message' => $double ? t('signup.success_double') : t('signup.success_single')];
            } elseif (!$r['mail_ok']) {
                $success = ['type' => 'warning', 'message' => $double ? t('signup.success_double_mail_failed') : t('signup.success_single_mail_failed')];
            } else {
                $success = ['type' => 'success', 'message' => $double ? t('signup.success_double') : t('signup.success_single')];
            }
        }
    }
}

$privacy = trim((string) setting('privacy_url', '')) ?: nm_link('privacy.php');
nm_layout_start('public', t('signup.title'));
?>
<h1><?= e(t('signup.title')) ?></h1>

<?php if ($success): ?>
  <div class="alert alert-<?= e($success['type']) ?>" role="status"><?= e($success['message']) ?></div>
  <p><a href="<?= e(nm_link('index.php')) ?>"><?= e(t('signup.back')) ?></a></p>
<?php else: ?>
  <p class="lead"><?= e(t('signup.intro', ['site' => nm_site_name()])) ?></p>

  <?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" action="<?= e(nm_link('index.php')) ?>" class="card form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="stamp" value="<?= e(Tokens::formStamp()) ?>">
    <div class="hp" aria-hidden="true">
      <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <label for="email"><?= e(t('signup.email')) ?></label>
    <input type="email" id="email" name="email" required autocomplete="email" maxlength="254" value="<?= e($old['email']) ?>" placeholder="<?= e(t('signup.email_placeholder')) ?>">

    <?php if ($feeds): ?>
      <fieldset>
        <legend><?= e(t('signup.choose_lists')) ?></legend>
        <label class="check">
          <input type="checkbox" name="general" value="1"<?= $old['general'] ? ' checked' : '' ?>>
          <span><?= e(nm_general_label()) ?></span>
        </label>
        <?php foreach ($feeds as $f): ?>
          <label class="check">
            <input type="checkbox" name="feeds[]" value="<?= (int) $f['id'] ?>"<?= in_array((int) $f['id'], $old['feeds'], true) ? ' checked' : '' ?>>
            <span><?= e($f['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
    <?php else: ?>
      <p class="muted"><?= e(t('signup.you_will_receive', ['list' => nm_general_label()])) ?></p>
    <?php endif; ?>

    <label class="check consent">
      <input type="checkbox" name="consent" value="1" required>
      <span><?= e(nm_consent_text()) ?> <a href="<?= e($privacy) ?>" target="_blank" rel="noopener"><?= e(t('signup.privacy_link')) ?></a></span>
    </label>

    <button type="submit" class="btn btn-primary"><?= e(t('signup.submit')) ?></button>
    <?php if (setting('optin_mode', 'single') === 'double'): ?>
      <p class="muted small"><?= e(t('signup.double_note')) ?></p>
    <?php endif; ?>
  </form>
<?php endif; ?>
<?php
nm_layout_end();
