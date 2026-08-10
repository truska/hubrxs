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
    $action = (string) ($_POST['action'] ?? 'verify');
    if ($action === 'choose_email') {
      if (hub_select_twofa_method('email', $error)) {
        hub_flash('info', 'We emailed you a 6-digit code to finish signing in.');
        hub_redirect('twofa.php');
      }
    } elseif ($action === 'choose_totp') {
      if (hub_select_twofa_method('totp', $error)) {
        hub_redirect('twofa.php');
      }
    } else {
      $method = hub_pending_twofa_method();
      $code = trim((string) ($_POST['code'] ?? ''));
      if ($method === '') {
        $error = 'Choose a verification method.';
      } elseif ($code === '') {
        $error = $method === 'totp' ? 'Enter the 6-digit code from your authenticator app.' : 'Enter the 6-digit code from your email.';
      } elseif (hub_complete_twofa($code, $error)) {
        hub_flash('success', 'Signed in successfully.');
        hub_redirect('dashboard.php');
      }
    }
  }
}

function hub_twofa_qr_data_uri(string $payload): string {
  $qrencode = is_file('/usr/bin/qrencode') ? '/usr/bin/qrencode' : trim((string) shell_exec('command -v qrencode'));
  if ($qrencode === '') {
    return '';
  }

  $inputFile = tempnam(sys_get_temp_dir(), 'hub_totp_uri_');
  $outputFile = tempnam(sys_get_temp_dir(), 'hub_totp_qr_');
  if ($inputFile === false || $outputFile === false) {
    return '';
  }

  try {
    file_put_contents($inputFile, $payload);
    chmod($inputFile, 0600);
    $cmd = escapeshellcmd($qrencode) . ' -o ' . escapeshellarg($outputFile) . ' -t PNG -s 7 -m 2 -r ' . escapeshellarg($inputFile);
    exec($cmd, $unused, $exitCode);
    if ($exitCode !== 0 || !is_file($outputFile) || filesize($outputFile) <= 0) {
      return '';
    }
    return 'data:image/png;base64,' . base64_encode((string) file_get_contents($outputFile));
  } finally {
    if (is_string($inputFile) && is_file($inputFile)) {
      unlink($inputFile);
    }
    if (is_string($outputFile) && is_file($outputFile)) {
      unlink($outputFile);
    }
  }
}

$messages = hub_flash_messages();
$method = hub_pending_twofa_method();
$setupRequired = hub_pending_twofa_setup_required();
$setupData = hub_pending_twofa_setup_data($pending);
$setupQrDataUri = $setupData ? hub_twofa_qr_data_uri((string) $setupData['otpauth_uri']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Two-factor</title>
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
      <?php if ($method === ''): ?>
        <h1>Choose verification method</h1>
        <p>Select how to finish signing in as <?php echo hub_h($pending['email'] ?? 'your account'); ?>.</p>
      <?php elseif ($method === 'totp' && $setupRequired): ?>
        <h1>Set up authenticator app</h1>
        <p>Add this account to Microsoft Authenticator, Google Authenticator, or another compatible app, then enter the 6-digit code.</p>
      <?php elseif ($method === 'totp'): ?>
        <h1>Authenticator code</h1>
        <p>Enter the 6-digit code from your authenticator app.</p>
      <?php else: ?>
        <h1>Enter the 6-digit code</h1>
        <p>We emailed a code to <?php echo hub_h($pending['email'] ?? 'your email'); ?>. Codes expire after 10 minutes.</p>
      <?php endif; ?>

      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>

      <?php if ($method === ''): ?>
        <form method="post" action="twofa.php" class="auth-choice-form">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <div class="links auth-choice-actions">
            <button type="submit" name="action" value="choose_totp" class="main-action">Authenticator App</button>
            <button class="but3" type="submit" name="action" value="choose_email">Email Code</button>
          </div>
        </form>
      <?php else: ?>
        <?php if ($method === 'totp' && $setupRequired && $setupData): ?>
          <?php if ($setupQrDataUri !== ''): ?>
            <div class="auth-setup-qr">
              <img src="<?php echo hub_h($setupQrDataUri); ?>" alt="Authenticator app QR code">
            </div>
          <?php endif; ?>
          <div class="auth-setup-key">
            <label>Setup Key</label>
            <code><?php echo hub_h((string) $setupData['secret']); ?></code>
            <a href="<?php echo hub_h((string) $setupData['otpauth_uri']); ?>">Open in authenticator app</a>
          </div>
        <?php endif; ?>

        <form method="post" action="twofa.php">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <input type="hidden" name="action" value="verify">
          <div>
            <label for="code">Code</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
          </div>
          <button class="but1" type="submit">Verify</button>
        </form>

        <div class="links auth-secondary-actions">
          <?php if ($method === 'email'): ?>
            <form method="post" action="twofa.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <button class="but3" type="submit" name="action" value="choose_totp">Use authenticator app</button>
            </form>
            <form method="post" action="twofa.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <button class="but3" type="submit" name="action" value="choose_email">Resend email code</button>
            </form>
          <?php elseif ($method === 'totp'): ?>
            <form method="post" action="twofa.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <button class="but3" type="submit" name="action" value="choose_email">Use email code</button>
            </form>
          <?php endif; ?>
          <a href="index.php">Back to login</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>