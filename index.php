<?php
require_once __DIR__ . '/includes/app/auth.php';

$error = null;

if (hub_is_logged_in()) {
  hub_redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $status = hub_login_with_password($email, $password, $error);
    if ($status === 'ok') {
      hub_redirect('dashboard.php');
    } elseif ($status === '2fa') {
      hub_flash('info', 'We emailed you a 6-digit code to finish signing in.');
      hub_redirect('twofa.php');
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
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand"><?php echo hub_h(HUB_APP_NAME); ?></p>
      <h1>Sign in</h1>
      <p>Secure access for clients. 2FA is enforced when enabled via preferences.</p>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>

      <form method="post" action="index.php">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <div>
          <label for="email">Email</label>
          <input id="email" name="email" type="email" required autocomplete="email" value="<?php echo hub_h($_POST['email'] ?? ''); ?>">
        </div>
        <div>
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <button type="submit">Continue</button>
        <div class="flex">
          <span class="muted">Need help?</span>
          <div class="links">
            <a href="forgot-password.php">Forgot password</a>
          </div>
        </div>
      </form>
    </div>

    <div class="card">
      <p class="brand">Security</p>
      <h1>Why this matters</h1>
      <p>Data is restricted by customer. Each user signs in with an email, strong password, and (when enabled) email 2FA.</p>
      <div class="badge">Daily/weekly JSON import ready</div>
      <p class="muted">Admin will provision customers and users. A password reset flow is available, and 2FA can be toggled via preference <code>pref2FA</code>.</p>
    </div>
  </div>
</body>
</html>
