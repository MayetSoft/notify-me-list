<?php
/**
 * Layout of the public pages (signup, confirmation, unsubscribe, self-service).
 * Variables: $title, $content, $flashes
 */
$privacy = trim((string) setting('privacy_url', '')) ?: nm_link('privacy.php');
?><!DOCTYPE html>
<html lang="<?= e(nm_language_code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> — <?= e(nm_site_name()) ?></title>
<link rel="stylesheet" href="<?= e(nm_link('assets/style.css')) ?>?v=<?= e(NM_VERSION) ?>">
</head>
<body class="public">
<header class="site-header">
  <div class="wrap narrow"><a class="brand" href="<?= e(nm_link('index.php')) ?>"><?= e(nm_site_name()) ?></a></div>
</header>
<main class="wrap narrow">
  <?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f[0]) ?>" role="status"><?= e($f[1]) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="site-footer">
  <div class="wrap narrow">
    <a href="<?= e(nm_link('manage.php')) ?>"><?= e(t('nav.manage')) ?></a>
    · <a href="<?= e($privacy) ?>"><?= e(t('nav.privacy')) ?></a>
  </div>
</footer>
<script src="<?= e(nm_link('assets/app.js')) ?>?v=<?= e(NM_VERSION) ?>"></script>
</body>
</html>
