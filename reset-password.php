<?php
require_once __DIR__ . '/includes/app/auth.php';

if (hub_is_logged_in()) {
  hub_redirect('dashboard.php');
}

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = null;
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    hub_log_auth_link_rejection('password_reset', $token, 'csrf_validation_failed');
    $error = 'Session expired. Please try again.';
  } else {
    $newPassword = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    if ($newPassword !== $confirmPassword) {
      $error = 'The passwords do not match. Please enter them again.';
    } elseif (hub_reset_password($token, $newPassword, $error)) {
      $done = true;
      hub_flash('success', 'Password updated. You can now sign in.');
      hub_redirect('index.php');
    }
  }
}

$messages = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Set new password</title>
  <link rel="stylesheet" href="/css/hub.css?v=20260714-auth-brand">
</head>
<body class="auth-page">
  <header class="auth-header" aria-label="<?php echo hub_h(HUB_APP_NAME); ?>">
    <a href="/dashboard.php" aria-label="<?php echo hub_h(HUB_APP_NAME); ?> dashboard">
      <img src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="<?php echo hub_h(HUB_APP_NAME); ?>">
    </a>
  </header>
  <div class="wrap auth-wrap">
    <div class="card">
      <h1>Choose a new password</h1>
      <p>Passwords must be at least <?php echo hub_password_min_length(); ?> characters.</p>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>

      <form method="post" action="reset-password.php">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="token" value="<?php echo hub_h($token); ?>">
        <div>
          <label for="password">New password</label>
          <div class="password-field">
            <input id="password" name="password" type="password" required autocomplete="new-password">
            <button class="password-reveal" type="button" aria-label="Show password while hovered" aria-pressed="false" data-password-reveal onmouseenter="this.previousElementSibling.type='text'; this.classList.add('is-visible');" onmouseleave="if (this.getAttribute('aria-pressed') !== 'true') { this.previousElementSibling.type='password'; this.classList.remove('is-visible'); }" onfocus="this.previousElementSibling.type='text'; this.classList.add('is-visible');" onblur="if (this.getAttribute('aria-pressed') !== 'true') { this.previousElementSibling.type='password'; this.classList.remove('is-visible'); }" onclick="var visible=this.getAttribute('aria-pressed') !== 'true'; this.setAttribute('aria-pressed', visible ? 'true' : 'false'); this.previousElementSibling.type=visible ? 'text' : 'password'; this.classList.toggle('is-visible', visible);">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
        </div>
        <div>
          <label for="confirm_password">Confirm new password</label>
          <div class="password-field">
            <input id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password">
            <button class="password-reveal" type="button" aria-label="Show confirmed password while hovered" aria-pressed="false" data-password-reveal onmouseenter="this.previousElementSibling.type='text'; this.classList.add('is-visible');" onmouseleave="if (this.getAttribute('aria-pressed') !== 'true') { this.previousElementSibling.type='password'; this.classList.remove('is-visible'); }" onfocus="this.previousElementSibling.type='text'; this.classList.add('is-visible');" onblur="if (this.getAttribute('aria-pressed') !== 'true') { this.previousElementSibling.type='password'; this.classList.remove('is-visible'); }" onclick="var visible=this.getAttribute('aria-pressed') !== 'true'; this.setAttribute('aria-pressed', visible ? 'true' : 'false'); this.previousElementSibling.type=visible ? 'text' : 'password'; this.classList.toggle('is-visible', visible);">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
        </div>
        <button class="but1" type="submit">Update password</button>
        <div class="links">
          <a class="but3" href="index.php">Back to login</a>
        </div>
      </form>
    </div>
  </div>
  <script src="/js/hub.js?v=20260622-password-reveal"></script>
</body>
</html>
