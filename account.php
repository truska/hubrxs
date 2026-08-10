<?php
require_once __DIR__ . '/includes/app/auth.php';
require_once __DIR__ . '/includes/app/images.php';
hub_require_login();

global $pdo, $DB_OK;

$user = hub_current_user();
$userId = (int) ($user['id'] ?? 0);
$error = null;
$messages = hub_flash_messages();

function hub_account_linkedin_value(string $linkedin): string {
  return trim($linkedin);
}

function hub_account_display_name(array $row): string {
  $name = trim((string) ($row['display_name'] ?? ''));
  if ($name !== '') {
    return $name;
  }
  $email = trim((string) ($row['email'] ?? ''));
  if ($email !== '' && strpos($email, '@') !== false) {
    return strstr($email, '@', true) ?: $email;
  }
  return $email !== '' ? $email : 'Account';
}

function hub_account_customer_label(array $row): string {
  $name = trim((string) ($row['customer_name'] ?? ''));
  $code = trim((string) ($row['customer_code'] ?? ''));
  if ($name !== '' && $code !== '') {
    return $name . ' (' . $code . ')';
  }
  return $name !== '' ? $name : $code;
}

function hub_account_totp_qr_data_uri(string $payload): string {
  $qrencode = is_file('/usr/bin/qrencode') ? '/usr/bin/qrencode' : trim((string) shell_exec('command -v qrencode'));
  if ($qrencode === '') {
    return '';
  }

  $inputFile = tempnam(sys_get_temp_dir(), 'hub_account_totp_uri_');
  $outputFile = tempnam(sys_get_temp_dir(), 'hub_account_totp_qr_');
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
function hub_account_load_user(int $userId): ?array {
  global $pdo, $DB_OK;
  if ($userId <= 0 || !$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user')) {
    return null;
  }
  $stmt = $pdo->prepare(
    'SELECT u.id, u.email, u.display_name, u.job_title, u.phone, u.linkedin, u.image, u.customer_id,
            c.name AS customer_name, c.code AS customer_code
     FROM hub_user u
     LEFT JOIN hub_customer c ON c.id = u.customer_id
     WHERE u.id = :id AND u.archived = 0
     LIMIT 1'
  );
  $stmt->execute([':id' => $userId]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$account = hub_account_load_user($userId);
if (!$account) {
  hub_flash('error', 'Account not found.');
  hub_redirect('/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (($_POST['action'] ?? '') === 'send_password_reset') {
    $result = hub_request_password_reset((string) ($account['email'] ?? ''));
    if (!empty($result['sent'])) {
      hub_flash('success', 'Password reset email sent.');
    } else {
      hub_flash('info', 'Password reset requested. Email sending may be unavailable.');
    }
    hub_redirect('/account.php');
  } elseif (($_POST['action'] ?? '') === 'start_auth_app_setup') {
    $setupError = null;
    $record = hub_totp_setup_for_user($account, $setupError);
    if ($record) {
      $_SESSION['hub_account_totp_setup'] = 1;
      hub_log_user_action([
        'user' => $account,
        'action_key' => 'auth_app_setup_started',
        'action_title' => 'Auth App setup started',
        'table_name' => 'hub_user_totp',
        'record_id' => $userId,
        'details' => ['method' => 'AuthApp'],
      ]);
      hub_flash('info', 'Scan the QR code and enter the authenticator code to enable Auth App login.');
    } else {
      hub_flash('error', $setupError ?: 'Unable to start authenticator setup.');
    }
    hub_redirect('/account.php');
  } elseif (($_POST['action'] ?? '') === 'verify_auth_app_setup') {
    $record = hub_totp_record_for_user($userId);
    $code = trim((string) ($_POST['totp_code'] ?? ''));
    $matchedCounter = null;
    if (!$record) {
      $error = 'Authenticator setup not found.';
      $_SESSION['hub_account_totp_setup'] = 1;
    } elseif (!hub_totp_verify_code((string) ($record['secret'] ?? ''), $code, $matchedCounter)) {
      $error = 'Invalid authenticator code.';
      $_SESSION['hub_account_totp_setup'] = 1;
    } else {
      $totpParams = [':last_counter' => $matchedCounter, ':user_id' => $userId];
      $stmt = $pdo->prepare('UPDATE hub_user_totp SET enabled = 1, confirmed_at = COALESCE(confirmed_at, NOW()), last_counter = :last_counter, modified = NOW() WHERE user_id = :user_id LIMIT 1');
      $stmt->execute($totpParams);
      $stmtUser = $pdo->prepare('UPDATE hub_user SET twofa_enabled = 1, modified = NOW() WHERE id = :id LIMIT 1');
      $stmtUser->execute([':id' => $userId]);
      unset($_SESSION['hub_account_totp_setup']);
      hub_log_user_action([
        'user' => $account,
        'action_key' => 'auth_app_enabled',
        'action_title' => 'Auth App enabled',
        'table_name' => 'hub_user_totp',
        'record_id' => $userId,
        'sql_text' => 'UPDATE hub_user_totp SET enabled = 1, confirmed_at = COALESCE(confirmed_at, NOW()), last_counter = :last_counter, modified = NOW() WHERE user_id = :user_id LIMIT 1',
        'sql_params' => $totpParams,
        'details' => ['method' => 'AuthApp'],
      ]);
      hub_flash('success', 'Authenticator app login enabled.');
      hub_redirect('/account.php');
    }
  } elseif (($_POST['action'] ?? '') === 'update_account') {
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $linkedin = hub_account_linkedin_value((string) ($_POST['linkedin'] ?? ''));

    $imageShouldChange = !empty($_POST['remove_profile_image']);
    $imageFilename = null;
    $profileUpload = $_FILES['profile_image'] ?? null;
    if (is_array($profileUpload) && (($profileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
      $namingUser = [
        'id' => $userId,
        'email' => (string) ($account['email'] ?? ''),
        'display_name' => $display !== '' ? $display : (string) ($account['display_name'] ?? ''),
      ];
      $targetCustomer = ['name' => (string) ($account['customer_name'] ?? ''), 'code' => (string) ($account['customer_code'] ?? '')];
      $uploadError = null;
      $imageFilename = hub_image_upload($profileUpload, 'user_profile', hub_image_user_base_name($namingUser, $targetCustomer), $uploadError);
      if ($imageFilename === null) {
        $error = $uploadError ?: 'Unable to upload profile image.';
      } else {
        $imageShouldChange = true;
      }
    }

    if ($error === null) {
      $imageSql = $imageShouldChange ? ', image = :image' : '';
      $stmt = $pdo->prepare(
        'UPDATE hub_user
         SET display_name = :display_name,
             phone = :phone,
             linkedin = :linkedin' . $imageSql . ',
             modified = NOW()
         WHERE id = :id AND archived = 0
         LIMIT 1'
      );
      $params = [
        ':display_name' => $display !== '' ? $display : null,
        ':phone' => $phone !== '' ? $phone : null,
        ':linkedin' => $linkedin !== '' ? $linkedin : null,
        ':id' => $userId,
      ];
      if ($imageShouldChange) {
        $params[':image'] = $imageFilename;
      }
      $stmt->execute($params);
      hub_log_user_action([
        'user' => $account,
        'action_key' => 'account_updated',
        'action_title' => 'Account updated',
        'table_name' => 'hub_user',
        'record_id' => $userId,
        'sql_text' => 'UPDATE hub_user SET display_name = :display_name, phone = :phone, linkedin = :linkedin, modified = NOW() WHERE id = :id AND archived = 0 LIMIT 1',
        'sql_params' => $params,
        'details' => ['profile_image_changed' => $imageShouldChange],
      ]);
      hub_flash('success', 'Account updated.');
      hub_redirect('/account.php');
    }
  }
  $messages = hub_flash_messages();
  $account = hub_account_load_user($userId) ?: $account;
}

$displayName = hub_account_display_name($account);
$customerLabel = hub_account_customer_label($account);
$currentImage = trim((string) ($account['image'] ?? ''));
$currentImageSrc = $currentImage !== '' ? hub_image_public_path('content', 'sm', $currentImage) : '/filestore/images/admin/md/default-user.svg';
$totpRecord = hub_totp_record_for_user($userId);
$authAppEnabled = $totpRecord && (int) ($totpRecord['enabled'] ?? 0) === 1 && !empty($totpRecord['confirmed_at']);
$showAuthAppSetup = !$authAppEnabled && !empty($_SESSION['hub_account_totp_setup']);
$authAppSetupData = null;
$authAppQrDataUri = '';
if ($showAuthAppSetup && $totpRecord) {
  $authAppSetupData = [
    'secret' => (string) ($totpRecord['secret'] ?? ''),
    'otpauth_uri' => hub_totp_otpauth_uri($account, (string) ($totpRecord['secret'] ?? '')),
  ];
  $authAppQrDataUri = hub_account_totp_qr_data_uri((string) $authAppSetupData['otpauth_uri']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Account</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="portal-page account-page">
  <header class="portal-header">
    <a class="portal-logo" href="/dashboard.php" aria-label="RxSource Hub dashboard">
      <img class="portal-logo-image" src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="RxSource Hub">
    </a>
    <div class="portal-welcome">
      <?php echo hub_h($displayName); ?><?php echo $customerLabel !== '' ? ' [' . hub_h($customerLabel) . ']' : ''; ?>
    </div>
    <div class="portal-header-actions">
      <a class="portal-admin-link" href="/dashboard.php" title="Dashboard"><i class="fa-solid fa-table-columns" aria-hidden="true"></i><span>Dashboard</span></a>
      <a class="portal-admin-link" href="/logout.php" title="Logout"><i class="<?php echo hub_h(hub_nav_icon_class('logout')); ?>" aria-hidden="true"></i><span>Logout</span></a>
    </div>
  </header>

  <main class="portal-content account-content">
    <div class="wrap account-wrap">
      <div class="card account-card">
        <div class="account-card-header">
          <div class="account-heading">
          <div class="account-avatar-large">
            <img src="<?php echo hub_h($currentImageSrc); ?>" alt="">
          </div>
          <div>
            <p class="brand">Account</p>
            <h1>Manage Account</h1>
            <p class="muted">Update your contact details and profile image.</p>
          </div>
          </div>
          <a class="account-close-btn but2" href="/dashboard.php" aria-label="Close account page"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endif; ?>

        <form method="post" action="/account.php" enctype="multipart/form-data" class="account-form">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <input type="hidden" name="action" value="update_account">
          <div>
            <label>Email</label>
            <input type="email" value="<?php echo hub_h((string) ($account['email'] ?? '')); ?>" readonly>
          </div>
          <?php if ($customerLabel !== ''): ?>
            <div>
              <label>Customer</label>
              <input type="text" value="<?php echo hub_h($customerLabel); ?>" readonly>
            </div>
          <?php endif; ?>
          <div>
            <label for="account_display_name">Name</label>
            <input id="account_display_name" name="display_name" type="text" value="<?php echo hub_h((string) ($account['display_name'] ?? '')); ?>">
          </div>
          <div>
            <label for="account_phone">Telephone</label>
            <input id="account_phone" name="phone" type="tel" value="<?php echo hub_h((string) ($account['phone'] ?? '')); ?>">
          </div>
          <div>
            <label for="account_linkedin">LinkedIn URL</label>
            <input id="account_linkedin" name="linkedin" type="url" value="<?php echo hub_h((string) ($account['linkedin'] ?? '')); ?>" placeholder="https://www.linkedin.com/in/name">
          </div>
          <div class="user-image-upload-panel account-image-panel">
            <label for="account_profile_image">Profile Image</label>
            <?php if ($currentImage !== ''): ?>
              <div class="user-image-current">
                <img src="<?php echo hub_h(hub_image_public_path('content', 'xs', $currentImage)); ?>" alt="">
                <span><?php echo hub_h($currentImage); ?></span>
              </div>
              <label class="muted user-image-remove"><input type="checkbox" name="remove_profile_image" value="1"> Remove current image</label>
            <?php else: ?>
              <p class="muted">No profile image uploaded.</p>
            <?php endif; ?>
            <input id="account_profile_image" name="profile_image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
          </div>
          <div class="links account-actions">
            <button class="but1" type="submit">Save Account</button>
          </div>
        </form>

        <form method="post" action="/account.php" class="account-reset-form">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <input type="hidden" name="action" value="send_password_reset">
          <div>
            <h2>Password</h2>
            <p class="muted">Send a reset link to your email address.</p>
          </div>
          <div class="links account-actions">
            <button class="but2" type="submit">Reset Password</button>
          </div>
        </form>
        <div class="account-security-form">
          <div>
            <h2>Auth App</h2>
            <?php if ($authAppEnabled): ?>
              <p class="muted">Authenticator app login is enabled for this account.</p>
            <?php elseif ($showAuthAppSetup && $authAppSetupData): ?>
              <p class="muted">Scan the QR code, then enter the 6-digit code from your authenticator app.</p>
            <?php else: ?>
              <p class="muted">Enable sign in with Microsoft Authenticator, Google Authenticator, or another compatible app.</p>
            <?php endif; ?>
          </div>

          <?php if ($authAppEnabled): ?>
            <div class="account-security-status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Enabled</span></div>
          <?php elseif ($showAuthAppSetup && $authAppSetupData): ?>
            <div class="account-auth-app-setup">
              <?php if ($authAppQrDataUri !== ''): ?>
                <div class="auth-setup-qr">
                  <img src="<?php echo hub_h($authAppQrDataUri); ?>" alt="Authenticator app QR code">
                </div>
              <?php endif; ?>
              <div class="auth-setup-key">
                <label>Setup Key</label>
                <code><?php echo hub_h((string) $authAppSetupData['secret']); ?></code>
                <a href="<?php echo hub_h((string) $authAppSetupData['otpauth_uri']); ?>">Open in authenticator app</a>
              </div>
              <form method="post" action="/account.php" class="account-auth-app-code-form">
                <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                <input type="hidden" name="action" value="verify_auth_app_setup">
                <div>
                  <label for="account_totp_code">Authenticator Code</label>
                  <input id="account_totp_code" name="totp_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                </div>
                <div class="links account-actions">
                  <button class="but1" type="submit">Enable Auth App</button>
                </div>
              </form>
            </div>
          <?php else: ?>
            <form method="post" action="/account.php" class="account-auth-app-start-form">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <input type="hidden" name="action" value="start_auth_app_setup">
              <div class="links account-actions">
                <button class="but2" type="submit">Set Up Auth App</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
