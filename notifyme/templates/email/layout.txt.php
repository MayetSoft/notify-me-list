<?php
/**
 * Plain-text version of layout.html.php.
 * Variables: $siteName, $subject, $contentText, $unsubscribeUrl (may be ''), $manageUrl, $email
 */
?><?= $siteName ?>

<?= str_repeat('=', min(60, max(3, mb_strlen($siteName)))) ?>


<?= $contentText ?>


-- 
<?php if ($email !== ''): ?>
<?= t('email.footer_sent_to', ['email' => $email, 'site' => $siteName]) ?>

<?php endif; ?>
<?php if ($unsubscribeUrl !== ''): ?>
<?= t('email.unsubscribe') ?> : <?= $unsubscribeUrl ?>

<?php endif; ?>
<?= t('email.manage') ?> : <?= $manageUrl ?>

