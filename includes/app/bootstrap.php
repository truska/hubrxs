<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../../../private/dbcon.php';
require_once __DIR__ . '/prefs.php';

define('HUB_SESSION_KEY', 'hub_user');
define('HUB_PENDING_2FA_KEY', 'hub_pending_2fa');
define('HUB_APP_NAME', 'RxSource Hub');

function hub_h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function hub_base_url(string $path = ''): string {
  return cms_base_url($path);
}

function hub_public_asset_url(string $value, string $fallback = ''): string {
  $value = trim($value);
  if ($value === '') {
    $value = trim($fallback);
  }
  if ($value === '') {
    return '';
  }
  if (preg_match('/^https?:\/\//i', $value)) {
    return $value;
  }
  if ($value[0] === '/') {
    return $value;
  }

  foreach (['/filestore/images/content/md/', '/filestore/images/content/lg/', '/filestore/images/content/', '/filestore/images/admin/md/'] as $prefix) {
    if (is_file(__DIR__ . '/../..' . $prefix . $value)) {
      return $prefix . $value;
    }
  }

  return '/filestore/images/content/md/' . ltrim($value, '/');
}

function hub_site_logo_url(): string {
  return hub_public_asset_url((string) hub_pref('prefLogoReverse', ''), '/filestore/images/content/md/rxs-hub-logo.png');
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

function hub_table_column_exists(string $table, string $column): bool {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO)) {
    return false;
  }

  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  try {
    $safeTable = str_replace('`', '', $table);
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $safeTable . '` LIKE :column');
    $stmt->execute([':column' => $column]);
    $cache[$key] = (bool) $stmt->fetchColumn();
  } catch (PDOException $e) {
    $cache[$key] = false;
  }

  return $cache[$key];
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

function hub_email_absolute_url(string $path): string {
  $path = trim($path);
  if ($path === '') {
    return '';
  }
  if (preg_match('/^https?:\/\//i', $path)) {
    return $path;
  }
  return hub_base_url($path);
}

function hub_email_logo_url(): string {
  return hub_email_absolute_url(hub_public_asset_url((string) hub_pref('prefLogo', ''), '/filestore/images/content/md/rxs-hub-logo.png'));
}

function hub_email_user_name(?array $user, string $fallbackEmail = ''): string {
  $name = trim((string) ($user['display_name'] ?? ''));
  if ($name !== '') {
    return $name;
  }
  $email = trim((string) ($user['email'] ?? $fallbackEmail));
  if ($email !== '' && strpos($email, '@') !== false) {
    return strstr($email, '@', true) ?: $email;
  }
  return $email !== '' ? $email : 'there';
}

function hub_email_html(string $recipientName, array $paragraphs, ?string $link = null, string $buttonLabel = 'Open link'): string {
  $logoUrl = hub_email_logo_url();
  $safeName = hub_h($recipientName !== '' ? $recipientName : 'there');
  $body = '<p style="margin:0 0 16px;color:#26333b;font-size:16px;line-height:1.55;">Hello ' . $safeName . ',</p>';
  foreach ($paragraphs as $paragraph) {
    $paragraph = trim((string) $paragraph);
    if ($paragraph !== '') {
      $body .= '<p style="margin:0 0 16px;color:#26333b;font-size:16px;line-height:1.55;">' . nl2br(hub_h($paragraph)) . '</p>';
    }
  }
  if ($link !== null && trim($link) !== '') {
    $safeLink = hub_h($link);
    $safeButton = hub_h($buttonLabel);
    $body .= '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:22px 0;"><tr><td bgcolor="#ed1b2f" style="border-radius:6px;background:#ed1b2f;padding:15px 26px;"><a href="' . $safeLink . '" style="display:inline-block;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;line-height:1.2;">' . $safeButton . '</a></td></tr></table>';
    $body .= '<p style="margin:0 0 16px;color:#53636c;font-size:14px;line-height:1.5;">If the button does not work, copy and paste this link into your browser:<br><a href="' . $safeLink . '" style="color:#ed1b2f;word-break:break-all;">' . $safeLink . '</a></p>';
  }
  $body .= '<p style="margin:24px 0 10px;color:#26333b;font-size:16px;line-height:1.55;">Regards<br>RX Source Hub Team</p>';
  if ($logoUrl !== '') {
    $body .= '<img src="' . hub_h($logoUrl) . '" alt="RX Source Hub" style="display:block;width:150px;max-width:45%;height:auto;margin-top:10px;">';
  }

  return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f1f3f4;font-family:Arial,Helvetica,sans-serif;color:#26333b;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f3f4;margin:0;padding:24px 12px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #d7d7d7;border-radius:8px;"><tr><td style="padding:28px;">' . $body . '</td></tr></table></td></tr></table></body></html>';
}

function hub_email_text(string $recipientName, array $paragraphs, ?string $link = null): string {
  $lines = ['Hello ' . ($recipientName !== '' ? $recipientName : 'there') . ',', ''];
  foreach ($paragraphs as $paragraph) {
    $paragraph = trim((string) $paragraph);
    if ($paragraph !== '') {
      $lines[] = $paragraph;
      $lines[] = '';
    }
  }
  if ($link !== null && trim($link) !== '') {
    $lines[] = 'Link: ' . $link;
    $lines[] = '';
  }
  $lines[] = 'Regards';
  $lines[] = 'RX Source Hub Team';
  return implode("\n", $lines);
}

function hub_send_email(string $to, string $subject, string $body, bool $isHtml = false): bool {
  $from = (string) hub_pref('prefEmailFrom', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
  $fromName = trim((string) hub_pref('prefEmailFromName', HUB_APP_NAME));
  $replyTo = (string) hub_pref('prefEmailReplyTo', $from);

  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-type: ' . ($isHtml ? 'text/html' : 'text/plain') . '; charset=utf-8';
  $headers[] = 'From: ' . ($fromName ? ($fromName . ' <' . $from . '>') : $from);
  if ($replyTo) {
    $headers[] = 'Reply-To: ' . $replyTo;
  }

  return mail($to, $subject, $body, implode("\r\n", $headers));
}

function hub_send_template_email(string $to, string $subject, string $recipientName, array $paragraphs, ?string $link = null, string $buttonLabel = 'Open link'): bool {
  return hub_send_email($to, $subject, hub_email_html($recipientName, $paragraphs, $link, $buttonLabel), true);
}

function hub_current_request_ip(): ?string {
  foreach (["HTTP_CF_CONNECTING_IP", "HTTP_X_FORWARDED_FOR", "REMOTE_ADDR"] as $key) {
    $value = trim((string) ($_SERVER[$key] ?? ""));
    if ($value !== "") {
      if ($key === "HTTP_X_FORWARDED_FOR") {
        $parts = array_map("trim", explode(",", $value));
        return $parts[0] ?? $value;
      }
      return $value;
    }
  }
  return null;
}

function hub_sql_value_literal(mixed $value): string {
  global $pdo;
  if ($value === null) {
    return "NULL";
  }
  if (is_bool($value)) {
    return $value ? "1" : "0";
  }
  if (is_int($value) || is_float($value)) {
    return (string) $value;
  }
  if ($pdo instanceof PDO) {
    return $pdo->quote((string) $value);
  }
  return "'" . str_replace("'", "''", (string) $value) . "'";
}

function hub_sql_with_values(string $sql, array $params): string {
  if (empty($params)) {
    return $sql;
  }
  uksort($params, static function (string $left, string $right): int {
    return strlen($right) <=> strlen($left);
  });
  foreach ($params as $key => $value) {
    $placeholder = (string) $key;
    if ($placeholder === '') {
      continue;
    }
    if ($placeholder[0] !== ':') {
      $placeholder = ':' . $placeholder;
    }
    $sql = str_replace($placeholder, hub_sql_value_literal($value), $sql);
  }
  return $sql;
}
function hub_log_user_action(array $data): void {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists("hub_user_action_log")) {
    return;
  }

  $user = $data["user"] ?? null;
  if (!is_array($user) && !array_key_exists("user_id", $data) && !array_key_exists("user_email", $data)) {
    $user = function_exists("hub_current_user") ? hub_current_user() : null;
  }

  $details = $data["details"] ?? null;
  $detailsJson = null;
  if ($details !== null) {
    $detailsJson = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($detailsJson === false) {
      $detailsJson = null;
    }
  }

  try {
    $stmt = $pdo->prepare(
      "INSERT INTO hub_user_action_log
        (user_id, user_email, action_key, action_title, action_time, table_name, record_id, sql_text, ip_address, request_method, request_uri, user_agent, details_json, archived, created, modified)
       VALUES
        (:user_id, :user_email, :action_key, :action_title, NOW(), :table_name, :record_id, :sql_text, :ip_address, :request_method, :request_uri, :user_agent, :details_json, 0, NOW(), NOW())"
    );
    $stmt->execute([
      ":user_id" => isset($user["id"]) ? (int) $user["id"] : (isset($data["user_id"]) ? (int) $data["user_id"] : null),
      ":user_email" => (string) ($user["email"] ?? ($data["user_email"] ?? "")) ?: null,
      ":action_key" => (string) ($data["action_key"] ?? "action"),
      ":action_title" => (string) ($data["action_title"] ?? ($data["action"] ?? "Action")),
      ":table_name" => $data["table_name"] ?? null,
      ":record_id" => isset($data["record_id"]) ? (string) $data["record_id"] : null,
      ":sql_text" => isset($data["sql_text"], $data["sql_params"]) && is_array($data["sql_params"]) ? hub_sql_with_values((string) $data["sql_text"], $data["sql_params"]) : ($data["sql_text"] ?? null),
      ":ip_address" => $data["ip_address"] ?? hub_current_request_ip(),
      ":request_method" => $_SERVER["REQUEST_METHOD"] ?? null,
      ":request_uri" => array_key_exists("request_uri", $data) ? $data["request_uri"] : ($_SERVER["REQUEST_URI"] ?? null),
      ":user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
      ":details_json" => $detailsJson,
    ]);
  } catch (PDOException $e) {
    return;
  }
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
