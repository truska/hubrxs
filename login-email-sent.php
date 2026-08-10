<?php
require_once __DIR__ . '/includes/app/auth.php';

if (hub_is_logged_in()) {
  hub_redirect('dashboard.php');
}

$messages = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Check your email</title>
  <link rel="stylesheet" href="/css/hub.css?v=20260716-login-methods">
</head>
<body class="auth-page">
  <header class="auth-header" aria-label="<?php echo hub_h(HUB_APP_NAME); ?>">
    <a href="/dashboard.php" aria-label="<?php echo hub_h(HUB_APP_NAME); ?> dashboard">
      <img src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="<?php echo hub_h(HUB_APP_NAME); ?>">
    </a>
  </header>
  <div class="wrap auth-wrap">
    <div class="card">
      <h1>Check your email</h1>
      <p>We have sent a login link. Open it from your email to finish signing in.</p>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>

      <div class="links auth-secondary-actions auth-sent-actions">
        <a class="but2" href="index.php">Back to login</a>
      </div>
    </div>
  </div>
</body>
</html>