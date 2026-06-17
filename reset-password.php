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
    $error = 'Session expired. Please try again.';
  } else {
    $newPassword = (string) ($_POST['password'] ?? '');
    if (hub_reset_password($token, $newPassword, $error)) {
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
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand"><?php echo hub_h(HUB_APP_NAME); ?></p>
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
          <input id="password" name="password" type="password" required autocomplete="new-password">
        </div>
        <button type="submit">Update password</button>
        <div class="links">
          <a href="index.php">Back to login</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
