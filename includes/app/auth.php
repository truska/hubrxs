<?php
require_once __DIR__ . '/bootstrap.php';

function hub_schema_ready(): bool {
  return hub_table_exists('hub_user') && hub_table_exists('hub_customer');
}

function hub_current_user(): ?array {
  return $_SESSION[HUB_SESSION_KEY] ?? null;
}

function hub_is_logged_in(): bool {
  return isset($_SESSION[HUB_SESSION_KEY]['id']);
}

function hub_is_admin(): bool {
  return isset($_SESSION[HUB_SESSION_KEY]['role']) && $_SESSION[HUB_SESSION_KEY]['role'] === 'admin';
}

function hub_require_admin(): void {
  hub_require_login();
  if (!hub_is_admin()) {
    http_response_code(403);
    echo 'Admins only.';
    exit;
  }
}

function hub_set_customer_override(?int $customerId): void {
  if ($customerId === null) {
    unset($_SESSION['hub_customer_override']);
    return;
  }
  $_SESSION['hub_customer_override'] = $customerId;
}

function hub_current_customer_override(): ?int {
  if (!hub_is_admin()) {
    return null;
  }
  return isset($_SESSION['hub_customer_override']) ? (int) $_SESSION['hub_customer_override'] : null;
}

function hub_effective_customer_id(?array $user): ?int {
  $override = hub_current_customer_override();
  if ($override !== null) {
    return $override;
  }
  if (!$user) {
    return null;
  }
  return isset($user['customer_id']) ? (int) $user['customer_id'] : null;
}

function hub_require_login(): void {
  if (!hub_is_logged_in()) {
    hub_redirect('index.php?login=1');
  }
}

function hub_logout(): void {
  unset($_SESSION[HUB_SESSION_KEY], $_SESSION[HUB_PENDING_2FA_KEY]);
  session_regenerate_id(true);
}

function hub_find_user_by_email(string $email): ?array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    return null;
  }
  $trimmed = strtolower(trim($email));
  if ($trimmed === '') {
    return null;
  }

  try {
    $stmt = $pdo->prepare('SELECT * FROM hub_user WHERE email = :email AND archived = 0 LIMIT 1');
    $stmt->execute([':email' => $trimmed]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (PDOException $e) {
    return null;
  }
}

function hub_start_session(array $user): void {
  $_SESSION[HUB_SESSION_KEY] = [
    'id' => (int) $user['id'],
    'email' => (string) $user['email'],
    'display_name' => (string) ($user['display_name'] ?? ''),
    'customer_id' => isset($user['customer_id']) ? (int) $user['customer_id'] : null,
    'role' => (string) ($user['role'] ?? 'user'),
  ];
  session_regenerate_id(true);
}

function hub_record_login(array $user): void {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    return;
  }
  try {
    $stmt = $pdo->prepare(
      'UPDATE hub_user SET last_login_at = NOW(), last_login_ip = :ip, modified = NOW() WHERE id = :id LIMIT 1'
    );
    $stmt->execute([
      ':id' => (int) $user['id'],
      ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
  } catch (PDOException $e) {
    return;
  }
}

function hub_twofa_channel_enabled(): bool {
  return hub_2fa_globally_enabled();
}

function hub_create_twofa_challenge(array $user, string &$error = null): bool {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    $error = 'Database not available.';
    return false;
  }
  if (!hub_table_exists('hub_user_twofa')) {
    $error = '2FA table missing.';
    return false;
  }

  $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $hash = password_hash($code, PASSWORD_DEFAULT);

  try {
    $stmt = $pdo->prepare(
      'INSERT INTO hub_user_twofa
        (user_id, code_hash, code_sent_to, channel, expires_at, request_ip, created)
       VALUES
        (:user_id, :code_hash, :code_sent_to, :channel, DATE_ADD(NOW(), INTERVAL 10 MINUTE), :request_ip, NOW())'
    );
    $stmt->execute([
      ':user_id' => (int) $user['id'],
      ':code_hash' => $hash,
      ':code_sent_to' => (string) $user['email'],
      ':channel' => 'email',
      ':request_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
  } catch (PDOException $e) {
    $error = 'Unable to save 2FA challenge.';
    return false;
  }

  $subject = HUB_APP_NAME . ' login code';
  $body = "Hello,\n\nYour login code is: {$code}\nThis code expires in 10 minutes.\n\nIf you did not request this code, please ignore this email.";
  $sent = hub_send_email((string) $user['email'], $subject, $body);
  if (!$sent) {
    $error = 'Unable to send 2FA email.';
    return false;
  }

  $_SESSION[HUB_PENDING_2FA_KEY] = [
    'user_id' => (int) $user['id'],
    'email' => (string) $user['email'],
    'created_at' => time(),
  ];
  return true;
}

function hub_pending_twofa_user(): ?array {
  global $pdo, $DB_OK;
  $pending = $_SESSION[HUB_PENDING_2FA_KEY] ?? null;
  if (!$pending || !isset($pending['user_id'])) {
    return null;
  }
  if (!$DB_OK || !($pdo instanceof PDO)) {
    return null;
  }
  try {
    $stmt = $pdo->prepare('SELECT * FROM hub_user WHERE id = :id AND archived = 0 LIMIT 1');
    $stmt->execute([':id' => (int) $pending['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (PDOException $e) {
    return null;
  }
}

function hub_verify_twofa_code(int $userId, string $code, string &$error = null): bool {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    $error = 'Database not available.';
    return false;
  }
  if (!hub_table_exists('hub_user_twofa')) {
    $error = '2FA table missing.';
    return false;
  }

  try {
    $stmt = $pdo->prepare(
      'SELECT id, code_hash FROM hub_user_twofa
       WHERE user_id = :user_id
         AND used_at IS NULL
         AND expires_at > NOW()
       ORDER BY id DESC
       LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      $error = 'No active code found.';
      return false;
    }
    if (!password_verify($code, (string) $row['code_hash'])) {
      $error = 'Invalid code.';
      return false;
    }

    $update = $pdo->prepare('UPDATE hub_user_twofa SET used_at = NOW() WHERE id = :id LIMIT 1');
    $update->execute([':id' => (int) $row['id']]);
    return true;
  } catch (PDOException $e) {
    $error = 'Unable to verify code.';
    return false;
  }
}

function hub_login_with_password(string $email, string $password, string &$error = null): string {
  $user = hub_find_user_by_email($email);
  if (!$user) {
    $error = 'Invalid credentials.';
    return 'error';
  }
  if ((int) ($user['login_enabled'] ?? 1) !== 1 || (int) ($user['archived'] ?? 0) === 1) {
    $error = 'Account disabled.';
    return 'error';
  }

  $hash = (string) ($user['password_hash'] ?? '');
  if ($hash === '' || !password_verify($password, $hash)) {
    $error = 'Invalid credentials.';
    return 'error';
  }

  if (hub_twofa_channel_enabled()) {
    if (!hub_create_twofa_challenge($user, $error)) {
      return 'error';
    }
    return '2fa';
  }

  hub_start_session($user);
  hub_record_login($user);
  return 'ok';
}

function hub_complete_twofa(string $code, string &$error = null): bool {
  $pending = hub_pending_twofa_user();
  if (!$pending) {
    $error = 'No pending login.';
    return false;
  }

  if (!hub_verify_twofa_code((int) $pending['id'], $code, $error)) {
    return false;
  }

  hub_start_session($pending);
  hub_record_login($pending);
  unset($_SESSION[HUB_PENDING_2FA_KEY]);
  return true;
}

function hub_password_min_length(): int {
  return 10;
}

function hub_validate_password(string $password, string &$error = null): bool {
  if (strlen($password) < hub_password_min_length()) {
    $error = 'Password must be at least ' . hub_password_min_length() . ' characters.';
    return false;
  }
  return true;
}

function hub_create_user(array $data, string &$error = null): ?int {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    $error = 'Database not available.';
    return null;
  }
  if (!hub_schema_ready()) {
    $error = 'Schema not ready.';
    return null;
  }

  $email = strtolower(trim((string) ($data['email'] ?? '')));
  $password = (string) ($data['password'] ?? '');
  if ($email === '' || $password === '') {
    $error = 'Email and password required.';
    return null;
  }
  if (!hub_validate_password($password, $error)) {
    return null;
  }

  $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
  $role = in_array($data['role'] ?? '', ['admin', 'user'], true) ? $data['role'] : 'user';

  try {
    $stmt = $pdo->prepare(
      'INSERT INTO hub_user
        (customer_id, email, password_hash, display_name, role, login_enabled, twofa_enabled, created, modified)
       VALUES
        (:customer_id, :email, :password_hash, :display_name, :role, 1, :twofa_enabled, NOW(), NOW())'
    );
    $stmt->execute([
      ':customer_id' => $customerId,
      ':email' => $email,
      ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
      ':display_name' => (string) ($data['display_name'] ?? ''),
      ':role' => $role,
      ':twofa_enabled' => hub_twofa_channel_enabled() ? 1 : 0,
    ]);
    return (int) $pdo->lastInsertId();
  } catch (PDOException $e) {
    $error = 'Unable to create user: ' . $e->getMessage();
    return null;
  }
}

function hub_request_password_reset(string $email): array {
  global $pdo, $DB_OK;

  $default = ['token' => null, 'link' => null, 'sent' => false];

  if (!$DB_OK || !($pdo instanceof PDO)) {
    return $default;
  }
  if (!hub_table_exists('hub_password_reset')) {
    return $default;
  }

  $user = hub_find_user_by_email($email);
  if (!$user) {
    // Do not leak account existence.
    return $default;
  }

  $token = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $token);

  try {
    $stmt = $pdo->prepare(
      'INSERT INTO hub_password_reset
        (user_id, token_hash, expires_at, request_ip, created)
       VALUES
        (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE), :request_ip, NOW())'
    );
    $stmt->execute([
      ':user_id' => (int) $user['id'],
      ':token_hash' => $tokenHash,
      ':request_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
  } catch (PDOException $e) {
    return $default;
  }

  $link = hub_base_url('reset-password.php?token=' . urlencode($token));
  $subject = HUB_APP_NAME . ' password reset';
  $body = "Hello,\n\nA password reset was requested for your account. If this was you, click the link below:\n{$link}\n\nThe link expires in 30 minutes. If you did not request this, you can ignore this email.";
  $sent = hub_send_email((string) $user['email'], $subject, $body);

  return [
    'token' => $token,
    'link' => $link,
    'sent' => $sent,
  ];
}

function hub_reset_password(string $token, string $newPassword, string &$error = null): bool {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    $error = 'Database not available.';
    return false;
  }
  if (!hub_table_exists('hub_password_reset')) {
    $error = 'Reset table missing.';
    return false;
  }
  if (!hub_validate_password($newPassword, $error)) {
    return false;
  }

  $tokenHash = hash('sha256', $token);
  try {
    $stmt = $pdo->prepare(
      'SELECT id, user_id FROM hub_password_reset
       WHERE token_hash = :token_hash
         AND used_at IS NULL
         AND expires_at > NOW()
       ORDER BY id DESC
       LIMIT 1'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      $error = 'Reset link expired or invalid.';
      return false;
    }

    $pdo->beginTransaction();

    $updateUser = $pdo->prepare('UPDATE hub_user SET password_hash = :hash, modified = NOW() WHERE id = :id LIMIT 1');
    $updateUser->execute([
      ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
      ':id' => (int) $row['user_id'],
    ]);

    $markToken = $pdo->prepare('UPDATE hub_password_reset SET used_at = NOW() WHERE id = :id LIMIT 1');
    $markToken->execute([':id' => (int) $row['id']]);

    $pdo->commit();
    return true;
  } catch (PDOException $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $error = 'Unable to reset password.';
    return false;
  }
}
