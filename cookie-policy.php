<?php
require_once __DIR__ . '/includes/app/content.php';
$isLoggedIn = hub_is_logged_in();
$pageKey = 'cookie_policy';
$contentPage = hub_content_page_get($pageKey);
$title = trim((string) ($contentPage['title'] ?? ''));
if ($title === '') {
  $title = (string) (hub_content_page_defaults($pageKey)['title'] ?? 'Policy');
}
$metaTitle = trim((string) ($contentPage['meta_title'] ?? ''));
if ($metaTitle === '') {
  $metaTitle = $title;
}
$metaDescription = trim((string) ($contentPage['meta_description'] ?? ''));
$canonicalUrl = trim((string) ($contentPage['canonical_url'] ?? ''));
$robots = trim((string) ($contentPage['robots'] ?? 'index,follow'));
if ($robots === '') {
  $robots = 'index,follow';
}
$published = (int) ($contentPage['published'] ?? 1) === 1;
$body = $published ? (string) ($contentPage['body'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | <?php echo hub_h($metaTitle); ?></title>
  <?php if ($metaDescription !== ''): ?><meta name="description" content="<?php echo hub_h($metaDescription); ?>"><?php endif; ?>
  <meta name="robots" content="<?php echo hub_h($robots); ?>">
  <?php if ($canonicalUrl !== ''): ?><link rel="canonical" href="<?php echo hub_h($canonicalUrl); ?>"><?php endif; ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="portal-page policy-page">
  <header class="portal-header">
    <a class="portal-logo" href="/dashboard.php" aria-label="RxSource Hub dashboard">
      <img class="portal-logo-image" src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="RxSource Hub">
    </a>
    <div class="portal-welcome"><?php echo hub_h($title); ?></div>
    <div class="portal-header-actions">
      <?php if ($isLoggedIn): ?>
        <a class="portal-admin-link" href="/dashboard.php" title="Dashboard"><i class="fa-solid fa-table-columns" aria-hidden="true"></i><span>Dashboard</span></a>
      <?php else: ?>
        <a class="portal-admin-link" href="/index.php" title="Login"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i><span>Login</span></a>
      <?php endif; ?>
    </div>
  </header>
  <main class="portal-content policy-content">
    <div class="wrap policy-wrap">
      <article class="card policy-card">
        <div class="policy-card-header">
          <p class="brand">Legal</p>
          <a class="account-close-btn but2" href="<?php echo $isLoggedIn ? '/dashboard.php' : '/index.php'; ?>" aria-label="Close policy page"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
        </div>
        <h1><?php echo hub_h($title); ?></h1>
        <div class="policy-body">
          <?php echo hub_content_render_text($body); ?>
        </div>
      </article>
    </div>
  </main>
</body>
</html>
