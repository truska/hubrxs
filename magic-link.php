<?php
require_once __DIR__ . "/includes/app/auth.php";

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$token = (string) ($_GET["token"] ?? $_POST['token'] ?? "");
$error = null;
$readyToConfirm = false;

if ($requestMethod === 'HEAD') {
  $probeRow = null;
  if (hub_inspect_magic_link($token, $probeRow) === 'valid' && $probeRow) {
    hub_log_magic_link_non_consuming_access($probeRow, 'magic_link_probe_ignored', 'Email login link probe ignored', 'HEAD');
  }
  http_response_code(204);
  exit;
}

if ($requestMethod === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Your confirmation session expired. Open the email link again.';
  } elseif (hub_complete_magic_link($token, $error)) {
    hub_flash("success", "Signed in successfully.");
    hub_redirect("dashboard.php");
  }
} else {
  $inspectionRow = null;
  $inspection = hub_inspect_magic_link($token, $inspectionRow);
  if ($inspection === 'valid' && $inspectionRow) {
    $readyToConfirm = true;
    hub_log_magic_link_non_consuming_access($inspectionRow, 'magic_link_confirmation_viewed', 'Email login confirmation viewed', 'GET');
  } else {
    $reason = hub_log_auth_link_rejection('magic_link', $token, $inspection);
    if ($reason === 'token_expired') {
      $error = 'This login link has expired.';
    } elseif ($reason === 'token_already_used') {
      $error = 'This login link has already been used.';
    } elseif ($reason === 'token_missing') {
      $error = 'The login link is missing its token.';
    } else {
      $error = 'This login link is invalid.';
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
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Login link</title>
  <link rel="stylesheet" href="/css/hub.css?v=20260716-login-options">
</head>
<body class="auth-page">
  <header class="auth-header" aria-label="<?php echo hub_h(HUB_APP_NAME); ?>">
    <a href="/dashboard.php" aria-label="<?php echo hub_h(HUB_APP_NAME); ?> dashboard">
      <img src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="<?php echo hub_h(HUB_APP_NAME); ?>">
    </a>
  </header>
  <div class="wrap auth-wrap">
    <div class="card">
      <h1>Login link</h1>
      <?php if ($readyToConfirm): ?>
        <p>Your login link is valid. Continue to sign in in this window.</p>
      <?php else: ?>
        <p>This login link could not be used.</p>
      <?php endif; ?>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg["type"]); ?>"><?php echo hub_h($msg["message"]); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>

      <?php if ($readyToConfirm): ?>
        <form method="post" action="/magic-link.php">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <input type="hidden" name="token" value="<?php echo hub_h($token); ?>">
          <button class="but1" type="submit">Continue signing in</button>
        </form>
      <?php endif; ?>

      <div class="links auth-secondary-actions">
        <a class="but1" href="index.php">Back to login</a>
      </div>
    </div>
  </div>
</body>
</html>
