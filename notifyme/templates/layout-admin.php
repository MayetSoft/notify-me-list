<?php
/**
 * Layout of the admin pages.
 * Variables: $title, $content, $flashes, $active
 */
$loggedIn = !empty($_SESSION['admin']['email']);
$nav = [
    'dashboard' => ['admin/index.php', t('nav.dashboard')],
    'send' => ['admin/send.php', t('nav.send')],
    'campaigns' => ['admin/campaigns.php', t('nav.campaigns')],
    'feeds' => ['admin/feeds.php', t('nav.feeds')],
    'import' => ['admin/import.php', t('nav.import')],
    'export' => ['admin/export.php', t('nav.export')],
    'cron' => ['admin/cron.php', t('nav.cron')],
    'settings' => ['admin/settings.php', t('nav.settings')],
    'smtp' => ['admin/smtp.php', t('nav.smtp')],
];
?><!DOCTYPE html>
<html lang="<?= e(nm_language_code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> — <?= e(t('admin.title')) ?></title>
<link rel="stylesheet" href="<?= e(nm_link('assets/style.css')) ?>?v=<?= e(NM_VERSION) ?>">
</head>
<body class="admin">
<header class="admin-header">
  <div class="wrap">
    <a class="brand" href="<?= e(nm_link('admin/index.php')) ?>"><?= e(nm_site_name()) ?> <span class="muted">· <?= e(t('admin.title')) ?></span></a>
    <?php if ($loggedIn): ?>
      <form method="post" action="<?= e(nm_link('admin/logout.php')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="link"><?= e(t('nav.logout')) ?></button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($loggedIn): ?>
  <nav class="wrap admin-nav" aria-label="Admin">
    <?php foreach ($nav as $key => $item): ?>
      <a href="<?= e(nm_link($item[0])) ?>"<?= $key === $active ? ' class="active" aria-current="page"' : '' ?>><?= e($item[1]) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</header>
<main class="wrap">
  <?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f[0]) ?>" role="status"><?= e($f[1]) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="site-footer"><div class="wrap muted">Notify Me List <?= e(NM_VERSION) ?> — <?= e(t('admin.footer_license')) ?></div></footer>
<script src="<?= e(nm_link('assets/app.js')) ?>?v=<?= e(NM_VERSION) ?>"></script>
</body>
</html>
