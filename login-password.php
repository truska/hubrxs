<?php
require_once __DIR__ . '/includes/app/auth.php';

if (hub_is_logged_in()) {
  hub_redirect('dashboard.php');
}

$email = strtolower(trim((string) ($_SESSION['hub_login_email'] ?? $_GET['email'] ?? $_POST['email'] ?? '')));
if ($email === '') {
  hub_flash('error', 'Enter your email address first.');
  hub_redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    $postedEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($postedEmail !== '' && $postedEmail !== $email) {
      $error = 'Email changed. Please start again.';
      unset($_SESSION['hub_login_email']);
    } else {
      $password = (string) ($_POST['password'] ?? '');
      $status = hub_login_with_password($email, $password, $error);
      if ($status === 'ok') {
        unset($_SESSION['hub_login_email']);
        hub_redirect('dashboard.php');
      } elseif ($status === '2fa') {
        unset($_SESSION['hub_login_email']);
        hub_flash('info', 'Choose how to finish signing in.');
        hub_redirect('twofa.php');
      }
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
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Password</title>
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
      <h1>Password</h1>
      <p>Enter your password for <?php echo hub_h($email); ?>.</p>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>

      <form method="post" action="login-password.php">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="email" value="<?php echo hub_h($email); ?>">
        <div>
          <label for="password">Password</label>
          <div class="password-field">
            <input id="password" name="password" type="password" required autocomplete="current-password">
            <button class="password-reveal" type="button" aria-label="Show password while hovered" aria-pressed="false" data-password-reveal onmouseenter="this.previousElementSibling.type='text'; this.classList.add('is-visible');" onmouseleave="if (this.getAttribute('aria-pressed') !== 'true') { this.previousElementSibling.type='password'; this.classList.remove('is-visible'); }" onfocus="this.previousElementSibling.type='text'; this.classList.add('is-visible');" onblur="if (this.getAttribute('aria-pressed') !== 'true') { this.previousElementSibling.type='password'; this.classList.remove('is-visible'); }" onclick="var visible=this.getAttribute('aria-pressed') !== 'true'; this.setAttribute('aria-pressed', visible ? 'true' : 'false'); this.previousElementSibling.type=visible ? 'text' : 'password'; this.classList.toggle('is-visible', visible);">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
        </div>
        <button class="but1" type="submit">Sign in</button>
        <div class="links auth-secondary-actions">
          <a class="but3" href="forgot-password.php?email=<?php echo rawurlencode($email); ?>">Forgot Password</a>
          <a class="but3" href="index.php">Use another method</a>
        </div>
      </form>
    </div>
  </div>
  <script src="/js/hub.js?v=20260622-password-reveal"></script>
</body>
</html>