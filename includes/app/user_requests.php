<?php
require_once __DIR__ . '/auth.php';

/** One notification goes to all enabled, non-archived Super Admin accounts. */
function hub_user_approval_recipients(): array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user')) return [];
  $stmt = $pdo->query("SELECT email FROM hub_user WHERE role = 'super_admin' AND login_enabled = 1 AND archived = 0 ORDER BY id ASC");
  $emails = [];
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
    $email = trim((string) $email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[strtolower($email)] = $email;
  }
  return array_values($emails);
}

/** Create a disabled user atomically, with no disclosed temporary password. */
function hub_create_pending_user(array $data, ?string &$error = null): ?int {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    $error = 'Database not available.';
    return null;
  }
  $data['role'] = 'user';
  $pdo->beginTransaction();
  try {
    $id = hub_create_user($data, $error);
    if (!$id) {
      $pdo->rollBack();
      return null;
    }
    $stmt = $pdo->prepare('UPDATE hub_user SET login_enabled=0, twofa_enabled=1, force_password_reset=1, modified=NOW() WHERE id=:id');
    $stmt->execute([':id' => $id]);
    $pdo->commit();
    return $id;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = 'Unable to create pending user.';
    return null;
  }
}
