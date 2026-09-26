<?php
/**
 * Plain-text twin of feed-digest.html.php (shown by text-only mail clients).
 * Same variables.
 */
?><?= $siteName ?>

<?= $intro ?>

<?php foreach ($groups as $group): ?>

<?= mb_strtoupper($group['feed']) ?>

<?= str_repeat('-', min(60, max(3, mb_strlen($group['feed'])))) ?>

<?php foreach ($group['items'] as $item): ?>

* <?= $item['title'] ?>

<?php if ($item['date'] !== ''): ?>
  <?= $item['date'] ?>

<?php endif; ?>
<?php if ($item['summary'] !== ''): ?>
  <?= wordwrap($item['summary'], 74, "\n  ") ?>

<?php endif; ?>
<?php if ($item['link'] !== ''): ?>
  <?= $item['link'] ?>

<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>


-- 
<?= t('digest.footer_why') ?>

<?= t('email.footer_sent_to', ['email' => $email, 'site' => $siteName]) ?>

<?= t('email.unsubscribe') ?> : <?= $unsubscribeUrl ?>

<?= t('digest.manage_feeds') ?> : <?= $manageUrl ?>

