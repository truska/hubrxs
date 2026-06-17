<?php
require_once __DIR__ . '/includes/app/auth.php';

if (hub_is_logged_in()) {
  hub_redirect('dashboard.php');
}

$error = null;
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    $email = (string) ($_POST['email'] ?? '');
    $result = hub_request_password_reset($email);
    $sent = true; // always show success to avoid leaking accounts
    $debugLink = $result['link'] ?? null;
    $mailSent = (bool) ($result['sent'] ?? false);
    if (!$mailSent) {
      $messages[] = ['type' => 'info', 'message' => 'Email send appears unavailable; use the debug reset link below.'];
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
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Reset password</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand"><?php echo hub_h(HUB_APP_NAME); ?></p>
      <h1>Reset your password</h1>
      <p>Enter your email and we will send a reset link (valid for 30 minutes).</p>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>
      <?php if ($sent): ?>
        <div class="alert success">If the email exists, a reset link has been sent.</div>
        <?php if (!empty($debugLink)): ?>
          <div class="alert info">Temporary debug reset URL (no email): <a href="<?php echo hub_h($debugLink); ?>"><?php echo hub_h($debugLink); ?></a></div>
        <?php endif; ?>
      <?php endif; ?>

      <form method="post" action="forgot-password.php">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <div>
          <label for="email">Email</label>
          <input id="email" name="email" type="email" required autocomplete="email" value="<?php echo hub_h($_POST['email'] ?? ''); ?>">
        </div>
        <button type="submit">Send reset link</button>
        <div class="links">
          <a href="index.php">Back to login</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
