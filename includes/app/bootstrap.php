<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../../../private/dbcon.php';
require_once __DIR__ . '/prefs.php';

define('HUB_SESSION_KEY', 'hub_user');
define('HUB_PENDING_2FA_KEY', 'hub_pending_2fa');
define('HUB_APP_NAME', 'RxS Hub');

function hub_h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function hub_base_url(string $path = ''): string {
  return cms_base_url($path);
}

function hub_csrf_token(): string {
  if (empty($_SESSION['hub_csrf'])) {
    $_SESSION['hub_csrf'] = bin2hex(random_bytes(32));
  }
  return (string) $_SESSION['hub_csrf'];
}

function hub_verify_csrf(string $token): bool {
  $sessionToken = $_SESSION['hub_csrf'] ?? '';
  return is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token);
}

function hub_table_exists(string $table): bool {
  global $pdo, $DB_OK;

  static $cache = [];
  if (isset($cache[$table])) {
    return $cache[$table];
  }

  if (!$DB_OK || !($pdo instanceof PDO)) {
    $cache[$table] = false;
    return false;
  }

  try {
    $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
    $stmt->execute([':table' => $table]);
    $cache[$table] = (bool) $stmt->fetchColumn();
    return $cache[$table];
  } catch (PDOException $e) {
    $cache[$table] = false;
    return false;
  }
}

function hub_pref(string $name, $default = null, string $scope = 'web') {
  return cms_pref($name, $default, $scope);
}

function hub_pref_bool(string $name, bool $default = false, string $scope = 'web'): bool {
  $value = strtolower((string) hub_pref($name, $default ? 'yes' : 'no', $scope));
  return in_array($value, ['1', 'yes', 'true', 'on'], true);
}

function hub_2fa_globally_enabled(): bool {
  $value = strtolower(trim((string) hub_pref('pref2FA', 'no')));
  return $value !== 'no';
}

function hub_send_email(string $to, string $subject, string $body): bool {
  $from = (string) hub_pref('prefEmailFrom', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
  $fromName = trim((string) hub_pref('prefEmailFromName', HUB_APP_NAME));
  $replyTo = (string) hub_pref('prefEmailReplyTo', $from);

  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-type: text/plain; charset=utf-8';
  $headers[] = 'From: ' . ($fromName ? ($fromName . ' <' . $from . '>') : $from);
  if ($replyTo) {
    $headers[] = 'Reply-To: ' . $replyTo;
  }

  return mail($to, $subject, $body, implode("\r\n", $headers));
}

function hub_redirect(string $path): void {
  header('Location: ' . $path);
  exit;
}

function hub_flash(string $type, string $message): void {
  if (!isset($_SESSION['hub_flash'])) {
    $_SESSION['hub_flash'] = [];
  }
  $_SESSION['hub_flash'][] = ['type' => $type, 'message' => $message];
}

function hub_flash_messages(): array {
  $messages = $_SESSION['hub_flash'] ?? [];
  unset($_SESSION['hub_flash']);
  return $messages;
}
