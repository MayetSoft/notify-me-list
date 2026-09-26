<?php
/**
 * Banner shown on admin pages while sending is paused because the SMTP
 * server reported a sending limit. Offers to resume immediately.
 */
$pausedUntil = Queue::pausedUntil();
if ($pausedUntil > 0): ?>
  <div class="alert alert-warning" role="status">
    <strong><?= e(t('campaign.host_paused', ['time' => nm_format_date($pausedUntil)])) ?></strong><br>
    <span class="small"><?= e((string) setting('send_paused_reason', '')) ?></span>
    <form method="post" action="<?= e(nm_link('admin/campaigns.php')) ?>" class="inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_pause">
      <button type="submit" class="btn btn-small"><?= e(t('campaign.resume_now')) ?></button>
    </form>
  </div>
<?php endif;
