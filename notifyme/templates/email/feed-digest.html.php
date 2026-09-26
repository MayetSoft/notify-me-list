<?php
/**
 * FEED UPDATE E-MAIL — the single, centralized template for feed notifications.
 * Edit this file to change the look of feed e-mails (the plain-text twin is
 * feed-digest.txt.php). Kept deliberately neutral so it suits any site.
 *
 * Variables:
 *   $siteName, $siteUrl, $subject, $intro, $email, $unsubscribeUrl, $manageUrl
 *   $groups = [ ['feed' => 'Feed name', 'items' => [
 *                 ['title' => ..., 'link' => ... (may be ''), 'summary' => ... (may be ''), 'date' => ... (may be '')], ...
 *             ]], ... ]
 * Everything is plain text and must be escaped with e().
 */
$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
?><!DOCTYPE html>
<html lang="<?= e(nm_language_code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;">
<div style="display:none;max-height:0;overflow:hidden;"><?= e($intro) ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f4;">
  <tr>
    <td align="center" style="padding:24px 12px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #e7e5e4;border-radius:8px;">
        <!-- Header -->
        <tr>
          <td style="padding:20px 28px;border-bottom:1px solid #e7e5e4;font-family:<?= $font ?>;">
            <div style="font-size:18px;font-weight:700;color:#1c1917;"><?= e($siteName) ?></div>
            <div style="font-size:14px;color:#78716c;margin-top:4px;"><?= e($intro) ?></div>
          </td>
        </tr>
        <!-- One section per feed -->
        <?php foreach ($groups as $group): ?>
        <tr>
          <td style="padding:22px 28px 4px;font-family:<?= $font ?>;">
            <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:700;color:#57534e;border-bottom:2px solid #1c1917;padding-bottom:6px;">
              <?= e($group['feed']) ?>
            </div>
          </td>
        </tr>
          <?php foreach ($group['items'] as $item): ?>
        <tr>
          <td style="padding:14px 28px;font-family:<?= $font ?>;border-bottom:1px solid #f5f5f4;">
            <div style="font-size:17px;line-height:1.35;font-weight:600;">
              <?php if ($item['link'] !== ''): ?>
                <a href="<?= e($item['link']) ?>" style="color:#1c1917;text-decoration:underline;text-decoration-color:#d6d3d1;"><?= e($item['title']) ?></a>
              <?php else: ?>
                <span style="color:#1c1917;"><?= e($item['title']) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($item['date'] !== ''): ?>
              <div style="font-size:13px;color:#a8a29e;margin-top:3px;"><?= e($item['date']) ?></div>
            <?php endif; ?>
            <?php if ($item['summary'] !== ''): ?>
              <div style="font-size:15px;line-height:1.55;color:#44403c;margin-top:8px;"><?= e($item['summary']) ?></div>
            <?php endif; ?>
            <?php if ($item['link'] !== ''): ?>
              <div style="margin-top:8px;font-size:14px;"><a href="<?= e($item['link']) ?>" style="color:#57534e;"><?= e(t('digest.read_more')) ?> →</a></div>
            <?php endif; ?>
          </td>
        </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <!-- Footer with the personal unsubscribe link -->
        <tr>
          <td style="padding:18px 28px 22px;border-top:1px solid #e7e5e4;font-family:<?= $font ?>;font-size:12px;line-height:1.5;color:#78716c;">
            <?= e(t('digest.footer_why')) ?><br>
            <?= e(t('email.footer_sent_to', ['email' => $email, 'site' => $siteName])) ?><br>
            <a href="<?= e($unsubscribeUrl) ?>" style="color:#57534e;"><?= e(t('email.unsubscribe')) ?></a>
            &nbsp;·&nbsp;
            <a href="<?= e($manageUrl) ?>" style="color:#57534e;"><?= e(t('digest.manage_feeds')) ?></a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
