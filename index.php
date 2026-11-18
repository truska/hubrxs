<?php
require_once __DIR__ . "/includes/app/auth.php";

if (hub_is_logged_in()) {
  hub_redirect("dashboard.php");
}

$error = null;
$continuedEmail = '';
$emailAccepted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $continuedEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!filter_var($continuedEmail, FILTER_VALIDATE_EMAIL)) {
    $error = 'Enter a valid email address.';
  } elseif (!hub_auth_rate_limit('continue', $continuedEmail)) {
    $error = 'Too many attempts. Please try again later.';
  } else {
    // This is an email format check, not account or mailbox verification.
    $emailAccepted = true;
    $_SESSION['hub_login_email'] = $continuedEmail;
    unset($_SESSION[HUB_PENDING_2FA_KEY], $_SESSION['hub_totp_login']);
    $action = (string) ($_POST['action'] ?? 'continue');
    if ($action === 'password') {
      hub_redirect('login-password.php');
    } elseif ($action === 'auth_app') {
      hub_start_totp_login($continuedEmail, $error);
      hub_redirect('login-auth-app.php');
    } elseif ($action === 'email_link') {
      hub_auth_defer_mail('link', $continuedEmail);
      hub_redirect('login-email-sent.php');
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
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Login</title>
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
      <h1>Sign in</h1>
      <p>Please enter your email address and select your login method.</p>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>

      <form method="post" action="index.php" class="auth-email-form">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="continue">
        <div>
          <label for="email">Email</label>
          <input id="email" name="email" type="email" required autocomplete="email" value="<?php echo hub_h($continuedEmail); ?>" data-continued-email="<?php echo hub_h($emailAccepted ? $continuedEmail : ''); ?>">
        </div>
        <button id="continue-email-button" class="but1" type="submit"<?php echo $emailAccepted ? ' disabled' : ''; ?>>Continue</button>
      </form>

      <div class="auth-login-options" aria-label="Login methods">
        <div class="links auth-choice-actions">
          <?php if ($emailAccepted): ?>
            <form method="post" action="index.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <input type="hidden" name="email" value="<?php echo hub_h($continuedEmail); ?>">
              <button class="but2" type="submit" name="action" value="password" data-login-method-button>Password</button>
            </form>
            <form method="post" action="index.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <input type="hidden" name="email" value="<?php echo hub_h($continuedEmail); ?>">
              <button class="but2" type="submit" name="action" value="auth_app" data-login-method-button>Auth App</button>
            </form>
            <form method="post" action="index.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <input type="hidden" name="email" value="<?php echo hub_h($continuedEmail); ?>">
              <button class="but2" type="submit" name="action" value="email_link" data-login-method-button>Email Link</button>
            </form>
          <?php else: ?>
            <button class="but3" type="button" disabled>Password</button>
            <button class="but3" type="button" disabled>Auth App</button>
            <button class="but3" type="button" disabled>Email Link</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <script>
    (function () {
      var email = document.getElementById('email');
      var continueButton = document.getElementById('continue-email-button');
      if (!email || !continueButton) return;
      var continuedEmail = (email.getAttribute('data-continued-email') || '').trim().toLowerCase();
      var methodButtons = Array.prototype.slice.call(document.querySelectorAll('[data-login-method-button]'));
      var originalDisabled = methodButtons.map(function (button) { return button.disabled; });

      email.addEventListener('input', function () {
        var current = email.value.trim().toLowerCase();
        var changed = continuedEmail !== '' && current !== continuedEmail;
        continueButton.disabled = continuedEmail !== '' && !changed;
        methodButtons.forEach(function (button, index) {
          button.disabled = changed ? true : originalDisabled[index];
        });
      });
    }());
  </script>
</body>
</html>
