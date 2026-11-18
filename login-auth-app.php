<?php
require_once __DIR__ . '/includes/app/auth.php';
if (hub_is_logged_in()) {
  hub_redirect('dashboard.php');
}
$email = (string) ($_SESSION['hub_totp_login']['email'] ?? '');
if ($email === '') {
  hub_redirect('index.php');
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (hub_complete_totp_login((string) ($_POST['code'] ?? ''), $error)) {
    hub_flash('success', 'Signed in successfully.');
    hub_redirect('dashboard.php');
  }
}
$retryAfter = hub_auth_login_retry_after($email);
$messages = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Authenticator code</title>
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
      <h1>Authenticator code</h1>
      <p>Enter the 6-digit code from your authenticator app for <?php echo hub_h($email); ?>.</p>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error && $retryAfter === 0): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>

      <form method="post" action="login-auth-app.php" data-auth-cooldown="<?php echo $retryAfter; ?>">
        <div class="alert info" data-auth-cooldown-message role="status"<?php echo $retryAfter > 0 ? '' : ' hidden'; ?>><?php if ($retryAfter > 0): ?>Too many attempts. Try again in <?php echo (int) ceil($retryAfter / 60); ?> minute<?php echo $retryAfter > 60 ? 's' : ''; ?>.<?php endif; ?></div>
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="email" value="<?php echo hub_h($email); ?>">
        <div>
          <label for="code">Code</label>
          <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
        </div>
        <button class="but1" type="submit" data-auth-submit<?php echo $retryAfter > 0 ? ' disabled' : ''; ?>>Sign in</button>
        <div class="links auth-secondary-actions">
          <a class="but3" href="index.php">Back to sign-in</a>
        </div>
      </form>
    </div>
  </div>
  <script src="/js/auth-cooldown.js?v=20261118-1"></script>
</body>
</html>