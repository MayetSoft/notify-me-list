<?php
/**
 * Shared HTML layout for campaigns and transactional e-mails.
 * Variables: $siteName, $siteUrl, $subject, $contentHtml, $unsubscribeUrl (may be ''), $manageUrl, $email
 * Inline styles only: many mail clients strip <style> blocks.
 */
?><!DOCTYPE html>
<html lang="<?= e(nm_language_code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f4;">
  <tr>
    <td align="center" style="padding:24px 12px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #e7e5e4;border-radius:8px;">
        <tr>
          <td style="padding:20px 28px;border-bottom:1px solid #e7e5e4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:18px;font-weight:700;color:#1c1917;">
            <?= e($siteName) ?>
          </td>
        </tr>
        <tr>
          <td style="padding:24px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#292524;">
            <?= $contentHtml /* already HTML: escaped by the caller or written by the admin */ ?>
          </td>
        </tr>
        <tr>
          <td style="padding:16px 28px 22px;border-top:1px solid #e7e5e4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#78716c;">
            <?php if ($email !== ''): ?>
              <?= e(t('email.footer_sent_to', ['email' => $email, 'site' => $siteName])) ?><br>
            <?php endif; ?>
            <?php if ($unsubscribeUrl !== ''): ?>
              <a href="<?= e($unsubscribeUrl) ?>" style="color:#57534e;"><?= e(t('email.unsubscribe')) ?></a>
              &nbsp;·&nbsp;
            <?php endif; ?>
            <a href="<?= e($manageUrl) ?>" style="color:#57534e;"><?= e(t('email.manage')) ?></a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
