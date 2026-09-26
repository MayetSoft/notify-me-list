<?php
/**
 * Admin: "Send a message" — compose, preview, send.
 * Sending creates a campaign with one queue row per recipient; the actual
 * sending happens chunk by chunk on the campaign page (and/or by cron).
 */
require __DIR__ . '/../_boot.php';
nm_require_ready();
nm_require_admin();

$feeds = Feeds::all();
$errors = [];
$preview = null;
$v = ['subject' => '', 'format' => 'text', 'body' => '', 'audience' => 'general', 'when' => 'now', 'send_at' => date('Y-m-d\\TH:00', nm_now() + 7200)];

if (nm_is_post()) {
    csrf_check();
    $v['subject'] = trim(preg_replace('/[\r\n]+/', ' ', nm_post('subject')));
    $v['format'] = nm_post('format') === 'html' ? 'html' : 'text';
    $v['body'] = isset($_POST['body']) && is_string($_POST['body']) ? trim($_POST['body']) : '';
    $aud = nm_post('audience');
    $v['audience'] = ($aud === 'all' || $aud === 'general' || (preg_match('/^feed:(\d+)$/', $aud, $m) && Feeds::find((int) $m[1]))) ? $aud : 'general';

    if ($v['subject'] === '' || mb_strlen($v['subject']) > 200) {
        $errors[] = t('send.err_subject');
    }
    if ($v['body'] === '') {
        $errors[] = t('send.err_body');
    }
    // Scheduling: the date is typed in the site's time zone (Admin > Settings).
    $v['when'] = nm_post('when') === 'later' ? 'later' : 'now';
    $v['send_at'] = nm_post('send_at', $v['send_at']);
    $sendAt = null;
    if ($v['when'] === 'later') {
        $sendAt = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v['send_at']) ? strtotime($v['send_at']) : false;
        if ($sendAt === false) {
            $errors[] = t('send.err_schedule_invalid');
        } elseif ($sendAt <= nm_now() + 60) {
            $errors[] = t('send.err_schedule_past');
        } elseif ($sendAt > nm_now() + 366 * 86400) {
            $errors[] = t('send.err_schedule_far');
        }
    }
    if (!$errors) {
        if ($v['format'] === 'html') {
            $html = $v['body'];
            $text = nm_html_to_text($html);
        } else {
            $text = $v['body'];
            $html = nm_text_to_html($text);
        }
        if (nm_post('action') === 'send') {
            if (!Mailer::isConfigured()) {
                $errors[] = t('dashboard.warn_smtp');
            } elseif ($sendAt === null && Queue::countAudience($v['audience']) === 0) {
                $errors[] = t('send.err_no_recipients');
            } elseif ($sendAt !== null) {
                $cid = Queue::createManual($v['subject'], $html, $text, $v['audience'], $sendAt);
                nm_log('queue', 'Manual campaign #' . $cid . ' scheduled for ' . date('c', $sendAt));
                flash('success', t('send.scheduled_ok', ['date' => nm_format_date($sendAt)]));
                nm_redirect(nm_link('admin/campaigns.php', ['id' => $cid]));
            } else {
                $cid = Queue::createManual($v['subject'], $html, $text, $v['audience']);
                nm_log('queue', 'Manual campaign #' . $cid . ' created for ' . $v['audience']);
                flash('success', t('send.queued'));
                nm_redirect(nm_link('admin/campaigns.php', ['id' => $cid, 'autostart' => 1]));
            }
        } else {
            // Preview with a fake subscriber: the real links are personalised at send time.
            $fake = ['id' => 0, 'email' => (string) setting('admin_email'), 'created_at' => 0, 'general_list' => 1];
            $preview = Mailer::wrap($v['subject'], $html, $text, $fake, nm_abs_url('unsubscribe.php', ['t' => 'preview']));
        }
    }
}

$audiences = ['general' => nm_general_label() . ' (' . Queue::countAudience('general') . ')'];
foreach ($feeds as $f) {
    $audiences['feed:' . $f['id']] = t('send.audience_feed', ['name' => $f['name']]) . ' (' . (int) $f['subscriber_count'] . ')';
}
$audiences['all'] = t('send.audience_all') . ' (' . Queue::countAudience('all') . ')';

nm_layout_start('admin', t('nav.send'), 'send');
?>
<h1><?= e(t('nav.send')) ?></h1>
<p class="muted"><?= e(t('send.intro', ['batch' => setting_int('batch_size', 1, 500), 'delay' => setting_int('send_delay_ms', 0, 60000)])) ?></p>

<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e(nm_link('admin/send.php')) ?>" class="form card">
  <?= csrf_field() ?>
  <label for="audience"><?= e(t('send.audience')) ?></label>
  <select id="audience" name="audience">
    <?php foreach ($audiences as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $v['audience'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="subject"><?= e(t('send.subject')) ?></label>
  <input id="subject" name="subject" required maxlength="200" value="<?= e($v['subject']) ?>">

  <fieldset class="inline-radios">
    <legend><?= e(t('send.format')) ?></legend>
    <label class="check"><input type="radio" name="format" value="text"<?= $v['format'] === 'text' ? ' checked' : '' ?>> <span><?= e(t('send.format_text')) ?></span></label>
    <label class="check"><input type="radio" name="format" value="html"<?= $v['format'] === 'html' ? ' checked' : '' ?>> <span><?= e(t('send.format_html')) ?></span></label>
  </fieldset>

  <label for="body"><?= e(t('send.body')) ?></label>
  <textarea id="body" name="body" rows="14" required class="mono"><?= e($v['body']) ?></textarea>
  <p class="help"><?= e(t('send.body_help')) ?></p>

  <fieldset class="schedule">
    <legend><?= e(t('send.when')) ?></legend>
    <label class="check"><input type="radio" name="when" value="now"<?= $v['when'] === 'now' ? ' checked' : '' ?>> <span><?= e(t('send.when_now')) ?></span></label>
    <label class="check"><input type="radio" name="when" value="later"<?= $v['when'] === 'later' ? ' checked' : '' ?>> <span><?= e(t('send.when_later')) ?></span></label>
    <input type="datetime-local" name="send_at" value="<?= e($v['send_at']) ?>" aria-label="<?= e(t('send.when_later')) ?>" step="60">
    <p class="help"><?= e(t('send.schedule_help', ['tz' => date_default_timezone_get()])) ?></p>
  </fieldset>

  <div class="button-row">
    <button type="submit" name="action" value="preview" class="btn"><?= e(t('send.preview')) ?></button>
    <button type="submit" name="action" value="send" class="btn btn-primary" data-confirm="<?= e(t('send.confirm')) ?>"><?= e(t('send.send')) ?></button>
  </div>
</form>

<?php if ($preview): ?>
<section class="card">
  <h2><?= e(t('send.preview_title')) ?></h2>
  <p class="muted small"><?= e(t('send.preview_help')) ?></p>
  <iframe class="preview" sandbox="" srcdoc="<?= e($preview[0]) ?>" title="<?= e(t('send.preview_title')) ?>"></iframe>
  <details><summary><?= e(t('send.preview_text')) ?></summary><pre class="code"><?= e($preview[1]) ?></pre></details>
</section>
<?php endif; ?>
<?php
nm_layout_end();
