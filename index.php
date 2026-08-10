<?php
require_once __DIR__ . "/includes/app/auth.php";

if (hub_is_logged_in()) {
  hub_redirect("dashboard.php");
}

function hub_login_available_user(string $email): ?array {
  $user = hub_find_user_by_email($email);
  if (!$user) {
    return null;
  }
  if ((int) ($user['login_enabled'] ?? 1) !== 1 || (int) ($user['archived'] ?? 0) === 1) {
    return null;
  }
  return $user;
}

function hub_login_totp_available(array $user): bool {
  $record = hub_totp_record_for_user((int) ($user['id'] ?? 0));
  return $record && (int) ($record['enabled'] ?? 0) === 1 && !empty($record['confirmed_at']);
}

$error = null;
$verifiedEmail = '';
$verifiedUser = null;
$totpAvailable = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (!hub_verify_csrf($_POST["csrf"] ?? "")) {
    $error = "Session expired. Please try again.";
  } else {
    $action = (string) ($_POST["action"] ?? "verify_email");
    $email = strtolower(trim((string) ($_POST["email"] ?? "")));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = "Enter a valid email address.";
    } else {
      $verifiedUser = hub_login_available_user($email);
      if (!$verifiedUser) {
        unset($_SESSION['hub_login_email']);
        $error = "That email address is not set up for Hub access.";
      } else {
        $verifiedEmail = (string) $verifiedUser['email'];
        $_SESSION['hub_login_email'] = $verifiedEmail;
        $totpAvailable = hub_login_totp_available($verifiedUser);

        if ($action === 'password') {
          hub_redirect('login-password.php');
        } elseif ($action === 'auth_app') {
          if (!$totpAvailable) {
            $error = "Authenticator app is not set up for this account yet. Sign in another way first, then set it up from your account.";
          } elseif (hub_start_totp_login($verifiedEmail, $error)) {
            hub_redirect('twofa.php');
          }
        } elseif ($action === 'email_link') {
          $result = hub_request_magic_link($verifiedEmail);
          $mailSent = (bool) ($result['sent'] ?? false);
          hub_flash('success', 'If the email can receive Hub mail, a login link has been sent.');
          if (!$mailSent && !empty($result['link'])) {
            hub_flash('info', 'Email send appears unavailable; temporary debug login URL: ' . (string) $result['link']);
          }
          hub_redirect('login-email-sent.php');
        }
      }
    }
  }
}

$postedEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
if ($verifiedEmail === '' && $postedEmail !== '') {
  $verifiedEmail = $postedEmail;
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
        <input type="hidden" name="action" value="verify_email">
        <div>
          <label for="email">Email</label>
          <input id="email" name="email" type="email" required autocomplete="email" value="<?php echo hub_h($verifiedEmail); ?>" data-verified-email="<?php echo hub_h($verifiedUser ? $verifiedEmail : ''); ?>">
        </div>
        <button id="verify-email-button" class="but1" type="submit"<?php echo $verifiedUser ? ' disabled' : ''; ?>>Verify Email Address</button>
      </form>

      <?php if ($verifiedUser && !$totpAvailable): ?>
        <div class="alert info">Authenticator app access is available after it has been set up from your account.</div>
      <?php endif; ?>

      <div class="auth-login-options" aria-label="Login methods">
        <div class="links auth-choice-actions">
          <?php if ($verifiedUser): ?>
            <form method="post" action="index.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <input type="hidden" name="email" value="<?php echo hub_h($verifiedEmail); ?>">
              <button class="but2" type="submit" name="action" value="password" data-login-method-button>Password</button>
            </form>
            <form method="post" action="index.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <input type="hidden" name="email" value="<?php echo hub_h($verifiedEmail); ?>">
              <button class="but2" type="submit" name="action" value="auth_app" <?php echo $totpAvailable ? '' : 'disabled'; ?> data-login-method-button>Auth App</button>
            </form>
            <form method="post" action="index.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <input type="hidden" name="email" value="<?php echo hub_h($verifiedEmail); ?>">
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
      var verify = document.getElementById('verify-email-button');
      if (!email || !verify) return;
      var verifiedEmail = (email.getAttribute('data-verified-email') || '').trim().toLowerCase();
      var methodButtons = Array.prototype.slice.call(document.querySelectorAll('[data-login-method-button]'));
      var originalDisabled = methodButtons.map(function (button) { return button.disabled; });

      email.addEventListener('input', function () {
        var current = email.value.trim().toLowerCase();
        var changed = verifiedEmail !== '' && current !== verifiedEmail;
        verify.disabled = verifiedEmail !== '' && !changed;
        methodButtons.forEach(function (button, index) {
          button.disabled = changed ? true : originalDisabled[index];
        });
      });
    }());
  </script>
</body>
</html>