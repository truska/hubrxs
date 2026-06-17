<?php
require_once __DIR__ . '/includes/app/auth.php';

if (hub_is_logged_in()) {
  hub_redirect('dashboard.php');
}

$pending = hub_pending_twofa_user();
if (!$pending) {
  hub_flash('error', 'No pending login.');
  hub_redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    $code = trim((string) ($_POST['code'] ?? ''));
    if ($code === '') {
      $error = 'Enter the 6-digit code from your email.';
    } else {
      if (hub_complete_twofa($code, $error)) {
        hub_flash('success', 'Signed in successfully.');
        hub_redirect('dashboard.php');
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
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Two-factor</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand"><?php echo hub_h(HUB_APP_NAME); ?></p>
      <h1>Enter the 6-digit code</h1>
      <p>We emailed a code to <?php echo hub_h($pending['email'] ?? 'your email'); ?>. Codes expire after 10 minutes.</p>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>

      <form method="post" action="twofa.php">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <div>
          <label for="code">Code</label>
          <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
        </div>
        <button type="submit">Verify</button>
        <div class="links">
          <a href="index.php">Back to login</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
