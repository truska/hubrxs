<?php
require_once __DIR__ . '/bootstrap.php';

function hub_schema_ready(): bool {
  return hub_table_exists('hub_user') && hub_table_exists('hub_customer');
}

function hub_current_user(): ?array {
  if (!isset($_SESSION[HUB_SESSION_KEY]['id'])) {
    return null;
  }

  global $pdo, $DB_OK;

  if ($DB_OK && ($pdo instanceof PDO)) {
    try {
      $stmt = $pdo->prepare('SELECT id, email, display_name, customer_id, role FROM hub_user WHERE id = :id AND archived = 0 LIMIT 1');
      $stmt->execute([':id' => (int) $_SESSION[HUB_SESSION_KEY]['id']]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($user) {
        $_SESSION[HUB_SESSION_KEY] = [
          'id' => (int) $user['id'],
          'email' => (string) $user['email'],
          'display_name' => (string) ($user['display_name'] ?? ''),
          'customer_id' => isset($user['customer_id']) ? (int) $user['customer_id'] : null,
          'role' => (string) ($user['role'] ?? 'user'),
        ];
      }
    } catch (PDOException $e) {
      return $_SESSION[HUB_SESSION_KEY];
    }
  }

  return $_SESSION[HUB_SESSION_KEY];
}

function hub_is_logged_in(): bool {
  return isset($_SESSION[HUB_SESSION_KEY]['id']);
}

function hub_role_hierarchy(): array {
  return [
    'user' => 10,
    'manager' => 20,
    'admin' => 30,
    'super_admin' => 40,
    'developer' => 50,
  ];
}

function hub_role_key(?array $user = null): string {
  if ($user === null) {
    $user = hub_current_user();
  }
  $role = trim((string) ($user['role'] ?? ($_SESSION[HUB_SESSION_KEY]['role'] ?? 'user')));
  return $role !== '' ? $role : 'user';
}

function hub_role_rank(?string $role = null): int {
  $role = $role !== null ? trim($role) : hub_role_key();
  $hierarchy = hub_role_hierarchy();
  return $hierarchy[$role] ?? $hierarchy['user'];
}

function hub_role_at_least(string $minimumRole, ?array $user = null): bool {
  return hub_role_rank(hub_role_key($user)) >= hub_role_rank($minimumRole);
}

function hub_user_role_options(bool $includeArchived = false): array {
  global $pdo, $DB_OK;

  if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_user_role')) {
    $where = $includeArchived ? '' : 'WHERE archived = 0';
    $stmt = $pdo->query('SELECT role_key, label, description, icon_class, rank FROM hub_user_role ' . $where . ' ORDER BY rank ASC, label ASC');
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    if ($rows) {
      return $rows;
    }
  }

  return [
    ['role_key' => 'user', 'label' => 'User', 'description' => '', 'icon_class' => 'fa-solid fa-user', 'rank' => 10],
    ['role_key' => 'manager', 'label' => 'Manager', 'description' => '', 'icon_class' => 'fa-solid fa-user-gear', 'rank' => 20],
    ['role_key' => 'admin', 'label' => 'Admin', 'description' => '', 'icon_class' => 'fa-solid fa-shield-halved', 'rank' => 30],
    ['role_key' => 'super_admin', 'label' => 'Super Admin', 'description' => '', 'icon_class' => 'fa-solid fa-crown', 'rank' => 40],
    ['role_key' => 'developer', 'label' => 'Developer', 'description' => '', 'icon_class' => 'fa-solid fa-code', 'rank' => 50],
  ];
}

function hub_valid_user_role_key(string $role): string {
  $role = trim($role);
  if ($role === '') {
    return 'user';
  }
  foreach (hub_user_role_options(true) as $option) {
    if (($option['role_key'] ?? '') === $role) {
      return $role;
    }
  }
  return 'user';
}

function hub_safe_icon_class(string $iconClass, string $fallback = 'fa-solid fa-circle'): string {
  $iconClass = trim($iconClass);
  if ($iconClass !== '' && preg_match('/^fa-[a-z0-9-]+(\s+fa-[a-z0-9-]+)*$/', $iconClass)) {
    return $iconClass;
  }
  return $fallback;
}

function hub_role_icon_class(string $roleKey): string {
  $roleKey = hub_valid_user_role_key($roleKey);
  foreach (hub_user_role_options(true) as $option) {
    if (($option['role_key'] ?? '') === $roleKey) {
      return hub_safe_icon_class((string) ($option['icon_class'] ?? ''), 'fa-solid fa-user');
    }
  }
  return 'fa-solid fa-user';
}

function hub_nav_icon_class(string $navKey): string {
  $map = [
    'client' => 'fa-solid fa-user',
    'manager' => hub_role_icon_class('manager'),
    'admin' => hub_role_icon_class('admin'),
    'super' => hub_role_icon_class('super_admin'),
    'dev' => hub_role_icon_class('developer'),
    'help' => 'fa-solid fa-circle-question',
    'logout' => 'fa-solid fa-right-from-bracket',
  ];
  return hub_safe_icon_class($map[$navKey] ?? 'fa-solid fa-circle', 'fa-solid fa-circle');
}

function hub_is_admin(): bool {
  return hub_role_at_least('admin');
}

function hub_is_manager(): bool {
  return hub_role_at_least('manager');
}

function hub_is_super_admin(): bool {
  return hub_role_at_least('super_admin');
}

function hub_is_developer(): bool {
  return hub_role_key() === 'developer';
}

function hub_can_manage_company_users(?array $user = null): bool {
  return hub_role_at_least('manager', $user);
}

function hub_can_manage_all_users(?array $user = null): bool {
  return hub_role_at_least('admin', $user);
}

function hub_can_access_developer_tools(?array $user = null): bool {
  return hub_role_key($user) === 'developer';
}

function hub_user_allowed_source_ids(?array $user = null): ?array {
  global $pdo, $DB_OK;

  if ($user === null) {
    $user = hub_current_user();
  }
  if (!$user || hub_role_rank(hub_role_key($user)) >= hub_role_rank('manager')) {
    return null;
  }
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user_import_source_access')) {
    return null;
  }

  $userId = (int) ($user['id'] ?? 0);
  if ($userId <= 0) {
    return [];
  }

  try {
    $stmtAny = $pdo->prepare('SELECT COUNT(*) FROM hub_user_import_source_access WHERE user_id = :user_id');
    $stmtAny->execute([':user_id' => $userId]);
    if ((int) $stmtAny->fetchColumn() === 0) {
      return null;
    }

    $stmtAllowed = $pdo->prepare(
      'SELECT source_id
       FROM hub_user_import_source_access
       WHERE user_id = :user_id
         AND can_access = 1
       ORDER BY source_id ASC'
    );
    $stmtAllowed->execute([':user_id' => $userId]);
    return array_map('intval', $stmtAllowed->fetchAll(PDO::FETCH_COLUMN) ?: []);
  } catch (PDOException $e) {
    return null;
  }
}

function hub_user_source_access_sql(string $sourceColumn, ?array $user, array &$params, string $prefix = 'source_access'): string {
  $allowedSourceIds = hub_user_allowed_source_ids($user);
  if ($allowedSourceIds === null) {
    return '';
  }
  if (empty($allowedSourceIds)) {
    return ' AND 1 = 0';
  }

  $placeholders = [];
  foreach ($allowedSourceIds as $index => $sourceId) {
    $key = ':' . $prefix . '_' . $index;
    $placeholders[] = $key;
    $params[$key] = (int) $sourceId;
  }

  return ' AND ' . $sourceColumn . ' IN (' . implode(', ', $placeholders) . ')';
}
function hub_user_allowed_project_ids(?array $user = null): ?array {
  global $pdo, $DB_OK;

  if ($user === null) {
    $user = hub_current_user();
  }
  if (!$user || hub_role_rank(hub_role_key($user)) >= hub_role_rank('manager')) {
    return null;
  }
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user_project_access')) {
    return null;
  }

  $userId = (int) ($user['id'] ?? 0);
  if ($userId <= 0) {
    return [];
  }

  try {
    $stmtAny = $pdo->prepare('SELECT COUNT(*) FROM hub_user_project_access WHERE user_id = :user_id');
    $stmtAny->execute([':user_id' => $userId]);
    if ((int) $stmtAny->fetchColumn() === 0) {
      return null;
    }

    $stmtAllowed = $pdo->prepare(
      'SELECT project_id
       FROM hub_user_project_access
       WHERE user_id = :user_id
         AND can_access = 1
       ORDER BY project_id ASC'
    );
    $stmtAllowed->execute([':user_id' => $userId]);
    return array_map('intval', $stmtAllowed->fetchAll(PDO::FETCH_COLUMN) ?: []);
  } catch (PDOException $e) {
    return null;
  }
}

function hub_user_project_access_sql(string $projectColumn, ?array $user, array &$params, string $prefix = 'project_access'): string {
  $allowedProjectIds = hub_user_allowed_project_ids($user);
  if ($allowedProjectIds === null) {
    return '';
  }
  if (empty($allowedProjectIds)) {
    return ' AND 1 = 0';
  }

  $placeholders = [];
  foreach ($allowedProjectIds as $index => $projectId) {
    $key = ':' . $prefix . '_' . $index;
    $placeholders[] = $key;
    $params[$key] = (int) $projectId;
  }

  return ' AND ' . $projectColumn . ' IN (' . implode(', ', $placeholders) . ')';
}
function hub_user_project_code_access_sql(string $projectCodeColumn, ?array $user, array &$params, string $prefix = 'project_code_access'): string {
  global $pdo, $DB_OK;

  $allowedProjectIds = hub_user_allowed_project_ids($user);
  if ($allowedProjectIds === null) {
    return '';
  }
  if (empty($allowedProjectIds)) {
    return ' AND 1 = 0';
  }
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_project')) {
    return ' AND 1 = 0';
  }

  $idPlaceholders = [];
  $idParams = [];
  foreach ($allowedProjectIds as $index => $projectId) {
    $key = ':allowed_project_id_' . $index;
    $idPlaceholders[] = $key;
    $idParams[$key] = (int) $projectId;
  }

  try {
    $stmt = $pdo->prepare('SELECT code FROM hub_project WHERE id IN (' . implode(', ', $idPlaceholders) . ') AND archived = 0');
    $stmt->execute($idParams);
    $codes = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), static function (string $code): bool {
      return trim($code) !== '';
    }));
  } catch (PDOException $e) {
    return ' AND 1 = 0';
  }

  if (empty($codes)) {
    return ' AND 1 = 0';
  }

  $placeholders = [];
  foreach ($codes as $index => $code) {
    $key = ':' . $prefix . '_' . $index;
    $placeholders[] = $key;
    $params[$key] = $code;
  }

  return ' AND ' . $projectCodeColumn . ' IN (' . implode(', ', $placeholders) . ')';
}
function hub_require_admin(): void {
  hub_require_login();
  if (!hub_is_admin()) {
    http_response_code(403);
    echo 'Admins only.';
    exit;
  }
}


function hub_require_manager(): void {
  hub_require_login();
  if (!hub_role_at_least('manager')) {
    http_response_code(403);
    echo 'Managers only.';
    exit;
  }
}

function hub_require_super_admin(): void {
  hub_require_login();
  if (!hub_role_at_least('super_admin')) {
    http_response_code(403);
    echo 'Super admins only.';
    exit;
  }
}

function hub_require_developer(): void {
  hub_require_login();
  if (!hub_is_developer()) {
    http_response_code(403);
    echo 'Developers only.';
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
  $user = hub_current_user();
  if ($user) {
    hub_log_user_action([
      'user' => $user,
      'action_key' => 'logout',
      'action_title' => 'Logout',
      'table_name' => 'hub_user',
      'record_id' => $user['id'] ?? null,
      'details' => ['method' => 'Logout'],
    ]);
  }
  unset($_SESSION[HUB_SESSION_KEY], $_SESSION[HUB_PENDING_2FA_KEY], $_SESSION['hub_customer_override']);
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
  unset($_SESSION['hub_customer_override']);
  $_SESSION[HUB_SESSION_KEY] = [
    'id' => (int) $user['id'],
    'email' => (string) $user['email'],
    'display_name' => (string) ($user['display_name'] ?? ''),
    'customer_id' => isset($user['customer_id']) ? (int) $user['customer_id'] : null,
    'role' => (string) ($user['role'] ?? 'user'),
  ];
  session_regenerate_id(true);
}

function hub_record_login(array $user, string $method = 'Login'): void {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    return;
  }
  try {
    $stmt = $pdo->prepare(
      'UPDATE hub_user SET last_login_at = NOW(), last_login_ip = :ip, modified = NOW() WHERE id = :id LIMIT 1'
    );
    $params = [
      ':id' => (int) $user['id'],
      ':ip' => hub_current_request_ip(),
    ];
    $stmt->execute($params);
    hub_log_user_action([
      'user' => $user,
      'action_key' => 'login',
      'action_title' => 'Login [' . $method . ']',
      'table_name' => 'hub_user',
      'record_id' => $user['id'] ?? null,
      'sql_text' => 'UPDATE hub_user SET last_login_at = NOW(), last_login_ip = :ip, modified = NOW() WHERE id = :id LIMIT 1',
      'sql_params' => $params,
      'request_uri' => $method === 'Email Link' ? '/magic-link.php' : ($_SERVER['REQUEST_URI'] ?? null),
      'details' => ['method' => $method, 'outcome' => 'success'],
    ]);
  } catch (PDOException $e) {
    return;
  }
}

function hub_record_login_failure(string $method, string $reason, ?array $user = null, string $attemptedEmail = ''): void {
  $attemptedEmail = strtolower(trim($attemptedEmail));
  hub_log_user_action([
    'user' => $user,
    'user_id' => isset($user['id']) ? (int) $user['id'] : null,
    'user_email' => (string) ($user['email'] ?? $attemptedEmail) ?: null,
    'action_key' => 'login_failed',
    'action_title' => 'Login [' . $method . ']',
    'table_name' => 'hub_user',
    'record_id' => $user['id'] ?? null,
    'details' => [
      'method' => $method,
      'outcome' => 'fail',
      'failure_reason' => $reason,
      'account_found' => $user !== null,
    ],
  ]);
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
  $sent = hub_send_template_email(
    (string) $user['email'],
    $subject,
    hub_email_user_name($user),
    [
      'Your login code is: ' . $code,
      'This code expires in 10 minutes. If you did not request this code, please ignore this email.',
    ]
  );
  if (!$sent) {
    $error = 'Unable to send 2FA email.';
    return false;
  }
  hub_log_user_action([
    'user' => $user,
    'action_key' => 'email_2fa_code_requested',
    'action_title' => 'Email 2FA code requested',
    'table_name' => 'hub_user_twofa',
    'record_id' => $user['id'] ?? null,
    'details' => ['method' => 'Email Code', 'sent' => $sent],
  ]);

  $_SESSION[HUB_PENDING_2FA_KEY] = [
    'user_id' => (int) $user['id'],
    'email' => (string) $user['email'],
    'method' => 'email',
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
    hub_record_login_failure('Password', 'account_not_found', null, $email);
    $error = 'Invalid credentials.';
    return 'error';
  }
  if ((int) ($user['login_enabled'] ?? 1) !== 1 || (int) ($user['archived'] ?? 0) === 1) {
    hub_record_login_failure('Password', 'account_disabled', $user, $email);
    $error = 'Account disabled.';
    return 'error';
  }

  $hash = (string) ($user['password_hash'] ?? '');
  if ($hash === '' || !password_verify($password, $hash)) {
    hub_record_login_failure('Password', 'invalid_password', $user, $email);
    $error = 'Invalid credentials.';
    return 'error';
  }

  if (hub_twofa_channel_enabled() || (int) ($user['twofa_enabled'] ?? 0) === 1) {
    $_SESSION[HUB_PENDING_2FA_KEY] = [
      'user_id' => (int) $user['id'],
      'email' => (string) $user['email'],
      'method' => '',
      'created_at' => time(),
    ];
    return '2fa';
  }

  hub_start_session($user);
  hub_record_login($user, 'Password');
  return 'ok';
}

function hub_start_totp_login(string $email, string &$error = null): bool {
  $user = hub_find_user_by_email($email);
  if (!$user || (int) ($user["login_enabled"] ?? 1) !== 1 || (int) ($user["archived"] ?? 0) === 1) {
    hub_record_login_failure('AuthApp', $user ? 'account_disabled' : 'account_not_found', $user, $email);
    $error = "Authenticator app is not available for that email. Use password to continue.";
    return false;
  }

  $record = hub_totp_record_for_user((int) $user["id"]);
  if (!$record || (int) ($record["enabled"] ?? 0) !== 1 || empty($record["confirmed_at"])) {
    hub_record_login_failure('AuthApp', 'authenticator_not_configured', $user, $email);
    $error = "Authenticator app is not available for that email. Use password to continue.";
    return false;
  }

  $_SESSION[HUB_PENDING_2FA_KEY] = [
    "user_id" => (int) $user["id"],
    "email" => (string) $user["email"],
    "method" => "totp",
    "setup_required" => 0,
    "created_at" => time(),
  ];
  return true;
}

function hub_complete_twofa(string $code, string &$error = null): bool {
  if (hub_pending_twofa_method() === 'totp') {
    return hub_complete_totp_twofa($code, $error);
  }

  $pending = hub_pending_twofa_user();
  if (!$pending) {
    $pendingData = $_SESSION[HUB_PENDING_2FA_KEY] ?? [];
    hub_record_login_failure('Email Code', 'no_pending_login', null, (string) ($pendingData['email'] ?? ''));
    $error = 'No pending login.';
    return false;
  }

  if (!hub_verify_twofa_code((int) $pending['id'], $code, $error)) {
    $reason = $error === 'Invalid code.' ? 'invalid_code' : ($error === 'No active code found.' ? 'code_missing_used_or_expired' : 'code_verification_error');
    hub_record_login_failure('Email Code', $reason, $pending);
    return false;
  }

  hub_start_session($pending);
  hub_record_login($pending, 'Email Code');
  unset($_SESSION[HUB_PENDING_2FA_KEY]);
  return true;
}

function hub_totp_base32_encode(string $bytes): string {
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $bits = '';
  $encoded = '';
  for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
    $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
  }
  foreach (str_split($bits, 5) as $chunk) {
    $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
  }
  return $encoded;
}

function hub_totp_base32_decode(string $secret): string {
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
  $bits = '';
  $decoded = '';
  for ($i = 0, $length = strlen($secret); $i < $length; $i++) {
    $pos = strpos($alphabet, $secret[$i]);
    if ($pos !== false) {
      $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
  }
  foreach (str_split($bits, 8) as $byte) {
    if (strlen($byte) === 8) {
      $decoded .= chr(bindec($byte));
    }
  }
  return $decoded;
}

function hub_totp_generate_secret(): string {
  return hub_totp_base32_encode(random_bytes(20));
}

function hub_totp_code_for_counter(string $secret, int $counter): string {
  $key = hub_totp_base32_decode($secret);
  $binaryCounter = pack('N*', 0) . pack('N*', $counter);
  $hash = hash_hmac('sha1', $binaryCounter, $key, true);
  $offset = ord(substr($hash, -1)) & 0x0F;
  $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
  return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function hub_totp_verify_code(string $secret, string $code, ?int &$matchedCounter = null): bool {
  $code = preg_replace('/\s+/', '', trim($code)) ?? '';
  if (!preg_match('/^\d{6}$/', $code)) {
    return false;
  }
  $current = (int) floor(time() / 30);
  for ($offset = -1; $offset <= 1; $offset++) {
    $counter = $current + $offset;
    if (hash_equals(hub_totp_code_for_counter($secret, $counter), $code)) {
      $matchedCounter = $counter;
      return true;
    }
  }
  return false;
}

function hub_totp_record_for_user(int $userId): ?array {
  global $pdo, $DB_OK;
  if ($userId <= 0 || !$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user_totp')) {
    return null;
  }
  try {
    $stmt = $pdo->prepare('SELECT * FROM hub_user_totp WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (PDOException $e) {
    return null;
  }
}

function hub_totp_setup_for_user(array $user, string &$error = null): ?array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    $error = 'Database not available.';
    return null;
  }
  if (!hub_table_exists('hub_user_totp')) {
    $error = 'Authenticator setup table missing.';
    return null;
  }
  $userId = (int) ($user['id'] ?? 0);
  $record = hub_totp_record_for_user($userId);
  if (!$record) {
    try {
      $stmt = $pdo->prepare('INSERT INTO hub_user_totp (user_id, secret, enabled, confirmed_at, last_counter, created, modified) VALUES (:user_id, :secret, 0, NULL, NULL, NOW(), NOW())');
      $stmt->execute([':user_id' => $userId, ':secret' => hub_totp_generate_secret()]);
      $record = hub_totp_record_for_user($userId);
    } catch (PDOException $e) {
      $error = 'Unable to create authenticator setup.';
      return null;
    }
  }
  return $record ?: null;
}

function hub_totp_otpauth_uri(array $user, string $secret): string {
  $issuer = HUB_APP_NAME;
  $account = (string) ($user['email'] ?? ('user-' . (int) ($user['id'] ?? 0)));
  return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

function hub_select_twofa_method(string $method, string &$error = null): bool {
  $user = hub_pending_twofa_user();
  if (!$user) {
    $error = 'No pending login.';
    return false;
  }
  if ($method === 'email') {
    return hub_create_twofa_challenge($user, $error);
  }
  if ($method === 'totp') {
    $record = hub_totp_setup_for_user($user, $error);
    if (!$record) {
      return false;
    }
    $_SESSION[HUB_PENDING_2FA_KEY]['method'] = 'totp';
    $_SESSION[HUB_PENDING_2FA_KEY]['setup_required'] = ((int) ($record['enabled'] ?? 0) !== 1 || empty($record['confirmed_at'])) ? 1 : 0;
    return true;
  }
  $error = 'Unknown 2FA method.';
  return false;
}

function hub_pending_twofa_method(): string {
  $pending = $_SESSION[HUB_PENDING_2FA_KEY] ?? [];
  return (string) ($pending['method'] ?? '');
}

function hub_pending_twofa_setup_required(): bool {
  $pending = $_SESSION[HUB_PENDING_2FA_KEY] ?? [];
  return hub_pending_twofa_method() === 'totp' && !empty($pending['setup_required']);
}

function hub_pending_twofa_setup_data(?array $user = null): ?array {
  if ($user === null) {
    $user = hub_pending_twofa_user();
  }
  if (!$user || hub_pending_twofa_method() !== 'totp') {
    return null;
  }
  $record = hub_totp_record_for_user((int) $user['id']);
  if (!$record) {
    return null;
  }
  return [
    'secret' => (string) ($record['secret'] ?? ''),
    'otpauth_uri' => hub_totp_otpauth_uri($user, (string) ($record['secret'] ?? '')),
  ];
}

function hub_complete_totp_twofa(string $code, string &$error = null): bool {
  global $pdo, $DB_OK;
  $pending = hub_pending_twofa_user();
  if (!$pending) {
    $pendingData = $_SESSION[HUB_PENDING_2FA_KEY] ?? [];
    hub_record_login_failure('AuthApp', 'no_pending_login', null, (string) ($pendingData['email'] ?? ''));
    $error = 'No pending login.';
    return false;
  }
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user_totp')) {
    hub_record_login_failure('AuthApp', 'authenticator_storage_unavailable', $pending);
    $error = 'Authenticator setup table missing.';
    return false;
  }
  $record = hub_totp_record_for_user((int) $pending['id']);
  if (!$record) {
    hub_record_login_failure('AuthApp', 'authenticator_not_configured', $pending);
    $error = 'Authenticator setup not found.';
    return false;
  }
  $matchedCounter = null;
  if (!hub_totp_verify_code((string) ($record['secret'] ?? ''), $code, $matchedCounter)) {
    hub_record_login_failure('AuthApp', 'invalid_code', $pending);
    $error = 'Invalid authenticator code.';
    return false;
  }
  $lastCounter = isset($record['last_counter']) ? (int) $record['last_counter'] : null;
  if ($lastCounter !== null && $matchedCounter !== null && $matchedCounter <= $lastCounter) {
    hub_record_login_failure('AuthApp', 'code_already_used', $pending);
    $error = 'This authenticator code has already been used.';
    return false;
  }
  try {
    $stmt = $pdo->prepare('UPDATE hub_user_totp SET enabled = 1, confirmed_at = COALESCE(confirmed_at, NOW()), last_counter = :last_counter, modified = NOW() WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':last_counter' => $matchedCounter, ':user_id' => (int) $pending['id']]);
  } catch (PDOException $e) {
    hub_record_login_failure('AuthApp', 'code_verification_error', $pending);
    $error = 'Unable to verify authenticator code.';
    return false;
  }
  hub_start_session($pending);
  hub_record_login($pending, 'AuthApp');
  unset($_SESSION[HUB_PENDING_2FA_KEY]);
  return true;
}
function hub_request_magic_link(string $email): array {
  global $pdo, $DB_OK;

  $default = ["token" => null, "link" => null, "sent" => false];
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists("hub_magic_link")) {
    return $default;
  }

  $user = hub_find_user_by_email($email);
  if (!$user || (int) ($user["login_enabled"] ?? 1) !== 1 || (int) ($user["archived"] ?? 0) === 1) {
    return $default;
  }

  $token = bin2hex(random_bytes(32));
  $tokenHash = hash("sha256", $token);

  try {
    $stmt = $pdo->prepare(
      "INSERT INTO hub_magic_link
        (user_id, token_hash, expires_at, request_ip, created)
       VALUES
        (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 15 MINUTE), :request_ip, NOW())"
    );
    $stmt->execute([
      ":user_id" => (int) $user["id"],
      ":token_hash" => $tokenHash,
      ":request_ip" => $_SERVER["REMOTE_ADDR"] ?? null,
    ]);
  } catch (PDOException $e) {
    return $default;
  }

  $link = hub_base_url("magic-link.php?token=" . urlencode($token));
  $subject = HUB_APP_NAME . " login link";
  $sent = hub_send_template_email(
    (string) $user["email"],
    $subject,
    hub_email_user_name($user),
    [
      'Use this link to sign in to ' . HUB_APP_NAME . '.',
      'The link expires in 15 minutes and can only be used once. If you did not request this, please ignore this email.',
    ],
    $link,
    'Sign in to Hub'
  );

  hub_log_user_action([
    'user' => $user,
    'action_key' => 'magic_link_requested',
    'action_title' => 'Magic link requested',
    'table_name' => 'hub_magic_link',
    'record_id' => $user['id'] ?? null,
    'details' => ['method' => 'Email Link', 'sent' => $sent],
  ]);

  return [
    "token" => $token,
    "link" => $link,
    "sent" => $sent,
  ];
}

/**
 * Record why a one-time authentication link was rejected without retaining the
 * raw token in either the structured details or the captured request URL.
 */
function hub_log_auth_link_rejection(string $linkType, string $token, ?string $forcedReason = null, array $extra = []): string {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !in_array($linkType, ['magic_link', 'password_reset'], true)) {
    return $forcedReason ?? (trim($token) === '' ? 'token_missing' : 'token_invalid');
  }

  $config = $linkType === 'magic_link'
    ? [
        'table' => 'hub_magic_link',
        'action_key' => 'magic_link_rejected',
        'action_title' => 'Email login link rejected',
        'request_uri' => '/magic-link.php',
      ]
    : [
        'table' => 'hub_password_reset',
        'action_key' => 'password_reset_rejected',
        'action_title' => 'Password reset rejected',
        'request_uri' => '/reset-password.php',
      ];

  if (!hub_table_exists($config['table'])) {
    return $forcedReason ?? 'token_storage_unavailable';
  }

  $row = null;
  $clock = [];
  try {
    $clock = $pdo->query(
      'SELECT NOW() AS database_now, UTC_TIMESTAMP() AS database_utc_now,
              @@session.time_zone AS session_time_zone,
              @@global.time_zone AS global_time_zone,
              @@system_time_zone AS system_time_zone'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    if (trim($token) !== '') {
      $stmt = $pdo->prepare(
        'SELECT l.id, l.user_id, l.created, l.expires_at, l.used_at,
                u.email AS user_email, u.login_enabled, u.archived
         FROM ' . $config['table'] . ' l
         LEFT JOIN hub_user u ON u.id = l.user_id
         WHERE l.token_hash = :token_hash
         ORDER BY l.id DESC
         LIMIT 1'
      );
      $stmt->execute([':token_hash' => hash('sha256', trim($token))]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
  } catch (PDOException $e) {
    $forcedReason = $forcedReason ?? 'diagnostic_query_failed';
    $extra['diagnostic_error_code'] = (string) $e->getCode();
  }

  $reason = $forcedReason;
  if ($reason === null) {
    if (trim($token) === '') {
      $reason = 'token_missing';
    } elseif (!$row) {
      $reason = 'token_not_found';
    } elseif (!empty($row['used_at'])) {
      $reason = 'token_already_used';
    } elseif (isset($clock['database_now']) && (string) $row['expires_at'] <= (string) $clock['database_now']) {
      $reason = 'token_expired';
    } elseif ($linkType === 'magic_link' && (int) ($row['archived'] ?? 0) === 1) {
      $reason = 'account_archived';
    } elseif ($linkType === 'magic_link' && (int) ($row['login_enabled'] ?? 1) !== 1) {
      $reason = 'account_login_disabled';
    } else {
      $reason = 'token_state_changed_or_unknown';
    }
  }

  $details = array_merge([
    'link_type' => $linkType,
    'rejection_reason' => $reason,
    'outcome' => $linkType === 'magic_link' ? 'fail' : null,
    'token_present' => trim($token) !== '',
    'record_found' => $row !== null,
    'created_at' => $row['created'] ?? null,
    'expires_at' => $row['expires_at'] ?? null,
    'used_at' => $row['used_at'] ?? null,
    'database_now' => $clock['database_now'] ?? null,
    'database_utc_now' => $clock['database_utc_now'] ?? null,
    'session_time_zone' => $clock['session_time_zone'] ?? null,
    'global_time_zone' => $clock['global_time_zone'] ?? null,
    'system_time_zone' => $clock['system_time_zone'] ?? null,
    'php_time_zone' => date_default_timezone_get(),
    'php_now' => date('Y-m-d H:i:s P'),
  ], $extra);

  hub_log_user_action([
    'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
    'user_email' => $row['user_email'] ?? null,
    'action_key' => $config['action_key'],
    'action_title' => $config['action_title'],
    'table_name' => $config['table'],
    'record_id' => $row['id'] ?? null,
    'request_uri' => $config['request_uri'],
    'details' => $details,
  ]);
  return $reason;
}

function hub_inspect_magic_link(string $token, ?array &$row = null): string {
  global $pdo, $DB_OK;
  $row = null;
  $token = trim($token);
  if ($token === '') return 'token_missing';
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_magic_link')) return 'token_storage_unavailable';
  try {
    $stmt = $pdo->prepare(
      'SELECT ml.id AS magic_link_id, ml.user_id, ml.created, ml.expires_at, ml.used_at,
              NOW() AS database_now, u.email, u.display_name, u.login_enabled, u.archived
       FROM hub_magic_link ml
       LEFT JOIN hub_user u ON u.id = ml.user_id
       WHERE ml.token_hash = :token_hash
       ORDER BY ml.id DESC LIMIT 1'
    );
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return 'token_not_found';
    if (!empty($row['used_at'])) return 'token_already_used';
    if ((string) $row['expires_at'] <= (string) $row['database_now']) return 'token_expired';
    if ((int) ($row['archived'] ?? 0) === 1) return 'account_archived';
    if ((int) ($row['login_enabled'] ?? 1) !== 1) return 'account_login_disabled';
    return 'valid';
  } catch (PDOException $e) {
    return 'database_error';
  }
}

function hub_log_magic_link_non_consuming_access(array $row, string $actionKey, string $actionTitle, string $method): void {
  hub_log_user_action([
    'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
    'user_email' => $row['email'] ?? null,
    'action_key' => $actionKey,
    'action_title' => $actionTitle,
    'table_name' => 'hub_magic_link',
    'record_id' => $row['magic_link_id'] ?? null,
    'request_uri' => '/magic-link.php',
    'details' => [
      'method' => $method,
      'outcome' => 'pending',
      'link_status' => 'not_used',
      'created_at' => $row['created'] ?? null,
      'expires_at' => $row['expires_at'] ?? null,
      'database_now' => $row['database_now'] ?? null,
    ],
  ]);
}

function hub_complete_magic_link(string $token, string &$error = null): bool {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    $error = "Database not available.";
    return false;
  }
  if (!hub_table_exists("hub_magic_link")) {
    $error = "Magic link table missing.";
    return false;
  }

  $token = trim($token);
  if ($token === "") {
    hub_log_auth_link_rejection('magic_link', $token, 'token_missing');
    $error = "Login link missing.";
    return false;
  }

  $tokenHash = hash("sha256", $token);
  try {
    $stmt = $pdo->prepare(
      "SELECT ml.id AS magic_link_id,
              ml.created AS magic_link_created,
              ml.expires_at AS magic_link_expires_at,
              u.*
       FROM hub_magic_link ml
       INNER JOIN hub_user u ON u.id = ml.user_id
       WHERE ml.token_hash = :token_hash
         AND ml.used_at IS NULL
         AND ml.expires_at > NOW()
         AND u.login_enabled = 1
         AND u.archived = 0
       ORDER BY ml.id DESC
       LIMIT 1"
    );
    $stmt->execute([":token_hash" => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      $reason = hub_log_auth_link_rejection('magic_link', $token);
      if ($reason === 'token_expired') {
        $error = "This login link has expired.";
      } elseif ($reason === 'token_already_used') {
        $error = "This login link has already been used.";
      } else {
        $error = "This login link is invalid.";
      }
      return false;
    }

    $pdo->beginTransaction();
    $markToken = $pdo->prepare("UPDATE hub_magic_link SET used_at = NOW() WHERE id = :id AND used_at IS NULL LIMIT 1");
    $markToken->execute([":id" => (int) $row["magic_link_id"]]);
    if ($markToken->rowCount() !== 1) {
      $pdo->rollBack();
      hub_log_auth_link_rejection('magic_link', $token, 'token_already_used_or_concurrent_request');
      $error = "This login link has already been used.";
      return false;
    }
    $pdo->commit();

    $linkAuditDetails = [
      'method' => 'Email Link',
      'outcome' => 'success',
      'link_status' => 'used',
      'created_at' => $row['magic_link_created'] ?? null,
      'expires_at' => $row['magic_link_expires_at'] ?? null,
      'used_at' => null,
      'database_now' => null,
      'database_utc_now' => null,
      'session_time_zone' => null,
      'global_time_zone' => null,
      'system_time_zone' => null,
      'php_time_zone' => date_default_timezone_get(),
      'php_now' => date('Y-m-d H:i:s P'),
    ];
    try {
      $clock = $pdo->query(
        'SELECT NOW() AS database_now, UTC_TIMESTAMP() AS database_utc_now,
                @@session.time_zone AS session_time_zone,
                @@global.time_zone AS global_time_zone,
                @@system_time_zone AS system_time_zone'
      )->fetch(PDO::FETCH_ASSOC) ?: [];
      $linkAuditDetails = array_merge($linkAuditDetails, $clock);
      $linkAuditDetails['used_at'] = $clock['database_now'] ?? null;
    } catch (PDOException $auditClockError) {
      $linkAuditDetails['audit_clock_error_code'] = (string) $auditClockError->getCode();
    }

    hub_log_user_action([
      'user' => $row,
      'action_key' => 'magic_link_used',
      'action_title' => 'Email login link used',
      'table_name' => 'hub_magic_link',
      'record_id' => $row['magic_link_id'] ?? null,
      'request_uri' => '/magic-link.php',
      'details' => $linkAuditDetails,
    ]);

    hub_start_session($row);
    hub_record_login($row, 'Email Link');
    unset($_SESSION[HUB_PENDING_2FA_KEY]);
    return true;
  } catch (PDOException $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    hub_log_auth_link_rejection('magic_link', $token, 'database_error', [
      'database_error_code' => (string) $e->getCode(),
    ]);
    $error = "Unable to use login link.";
    return false;
  }
}

function hub_password_min_length(): int {
  return 10;
}

function hub_generate_temporary_password(int $length = 16): string {
  $length = max(hub_password_min_length(), $length);
  $groups = [
    'ABCDEFGHJKLMNPQRSTUVWXYZ',
    'abcdefghijkmnopqrstuvwxyz',
    '23456789',
    '!@#$%_-',
  ];
  $password = '';
  foreach ($groups as $group) {
    $password .= $group[random_int(0, strlen($group) - 1)];
  }
  $all = implode('', $groups);
  while (strlen($password) < $length) {
    $password .= $all[random_int(0, strlen($all) - 1)];
  }
  $chars = str_split($password);
  for ($i = count($chars) - 1; $i > 0; $i--) {
    $j = random_int(0, $i);
    [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
  }
  return implode('', $chars);
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
  $role = hub_valid_user_role_key((string) ($data['role'] ?? 'user'));

  try {
    $stmt = $pdo->prepare(
      'INSERT INTO hub_user
        (customer_id, email, password_hash, display_name, job_title, phone, linkedin, role, login_enabled, twofa_enabled, created, modified)
       VALUES
        (:customer_id, :email, :password_hash, :display_name, :job_title, :phone, :linkedin, :role, 1, :twofa_enabled, NOW(), NOW())'
    );
    $stmt->execute([
      ':customer_id' => $customerId,
      ':email' => $email,
      ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
      ':display_name' => (string) ($data['display_name'] ?? ''),
      ':job_title' => trim((string) ($data['job_title'] ?? '')) !== '' ? trim((string) $data['job_title']) : null,
      ':phone' => trim((string) ($data['phone'] ?? '')) !== '' ? trim((string) $data['phone']) : null,
      ':linkedin' => trim((string) ($data['linkedin'] ?? '')) !== '' ? trim((string) $data['linkedin']) : null,
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
  $sent = hub_send_template_email(
    (string) $user['email'],
    $subject,
    hub_email_user_name($user),
    [
      'A password reset was requested for your account.',
      'The link expires in 30 minutes. If you did not request this, you can ignore this email.',
    ],
    $link,
    'Reset password'
  );

  hub_log_user_action([
    'user' => $user,
    'action_key' => 'password_reset_requested',
    'action_title' => 'Password reset requested',
    'table_name' => 'hub_password_reset',
    'record_id' => $user['id'] ?? null,
    'details' => ['sent' => $sent],
  ]);

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
    hub_log_auth_link_rejection('password_reset', $token, 'password_policy_rejected', [
      'validation_error' => $error,
    ]);
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
      $reason = hub_log_auth_link_rejection('password_reset', $token);
      if ($reason === 'token_expired') {
        $error = 'This password reset link has expired.';
      } elseif ($reason === 'token_already_used') {
        $error = 'This password reset link has already been used.';
      } else {
        $error = 'This password reset link is invalid.';
      }
      return false;
    }

    $pdo->beginTransaction();

    $markToken = $pdo->prepare('UPDATE hub_password_reset SET used_at = NOW() WHERE id = :id AND used_at IS NULL LIMIT 1');
    $markToken->execute([':id' => (int) $row['id']]);
    if ($markToken->rowCount() !== 1) {
      $pdo->rollBack();
      hub_log_auth_link_rejection('password_reset', $token, 'token_already_used_or_concurrent_request');
      $error = 'This password reset link has already been used.';
      return false;
    }

    $updateUser = $pdo->prepare('UPDATE hub_user SET password_hash = :hash, force_password_reset = 0, modified = NOW() WHERE id = :id LIMIT 1');
    $updateUser->execute([
      ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
      ':id' => (int) $row['user_id'],
    ]);

    $pdo->commit();
    hub_log_user_action([
      'user_id' => (int) $row['user_id'],
      'action_key' => 'password_reset_completed',
      'action_title' => 'Password reset completed',
      'table_name' => 'hub_user',
      'record_id' => $row['user_id'] ?? null,
      'sql_text' => 'UPDATE hub_user SET password_hash = :hash, force_password_reset = 0, modified = NOW() WHERE id = :id LIMIT 1',
    ]);
    return true;
  } catch (PDOException $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    hub_log_auth_link_rejection('password_reset', $token, 'database_error', [
      'database_error_code' => (string) $e->getCode(),
    ]);
    $error = 'Unable to reset password.';
    return false;
  }
}
