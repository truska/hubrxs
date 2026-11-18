<?php
// MySQL integration checks shadow real tables with connection-local TEMPORARY tables.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$testDirectory = sys_get_temp_dir() . '/hub-auth-mysql-' . bin2hex(random_bytes(8));
mkdir($testDirectory, 0700);
define('HUB_AUTH_RATE_LIMIT_DIR', $testDirectory . '/limits');
ini_set('session.save_path', $testDirectory);
require_once __DIR__ . '/../includes/app/auth.php';
if (!$DB_OK || !($pdo instanceof PDO)) {
  session_destroy(); rmdir($testDirectory);
  throw new RuntimeException('A development database connection is required.');
}
function auth_mysql_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
  $GLOBALS['checks'][] = $message;
}
$checks = [];
try {
  foreach (['hub_user', 'hub_user_totp', 'hub_user_twofa', 'hub_user_action_log'] as $table) {
    $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
    $create = preg_replace('/^\s*CONSTRAINT[^\n]+\n/m', '', $create);
    $create = preg_replace('/,\n\)/', "\n)", $create);
    $pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $create));
  }
  $pdo->prepare('INSERT INTO hub_user (id,email,password_hash,display_name,role,login_enabled,archived,twofa_enabled) VALUES (900001, ?, ?, ?, ?, 1, 0, 1)')->execute(['auth-test@example.invalid', password_hash('test-password', PASSWORD_DEFAULT), 'Isolated auth test', 'user']);
  $_SERVER['REMOTE_ADDR'] = '192.0.2.99';
  $error = null;
  auth_mysql_check(hub_login_with_password('auth-test@example.invalid', 'test-password', $error) === '2fa' && !hub_is_logged_in(), 'Password requires the second factor');
  auth_mysql_check(hub_pending_twofa_user() !== null, 'Verified primary credential permits pending verification');
  auth_mysql_check(hub_select_twofa_method('totp', $error), 'Authenticator setup can follow valid password');
  $setup = hub_pending_twofa_setup_data();
  auth_mysql_check($setup !== null && hub_pending_twofa_setup_required(), 'Unconfigured authenticator setup remains available after password');
  $code = hub_totp_code_for_counter($setup['secret'], (int) floor(time()/30));
  auth_mysql_check(hub_complete_twofa($code, $error) && hub_is_logged_in(), 'Completing authenticator setup signs in');
  hub_logout();
  auth_mysql_check(hub_login_with_password('auth-test@example.invalid', 'test-password', $error) === '2fa', 'Configured account still requires second factor');
  auth_mysql_check(hub_select_twofa_method('totp', $error), 'Existing authenticator can be selected');
  auth_mysql_check(hub_pending_twofa_setup_data() === null, 'Existing authenticator secret is not exposed');
  auth_mysql_check(!hub_complete_twofa($code, $error), 'Second-factor replay rejected');
  $nextCode = hub_totp_code_for_counter($setup['secret'], (int) floor(time()/30) + 1);
  auth_mysql_check(hub_complete_twofa($nextCode, $error) && hub_is_logged_in(), 'Fresh second-factor code signs in');
  hub_logout();
  auth_mysql_check(hub_login_with_password('auth-test@example.invalid', 'test-password', $error) === '2fa', 'Password can start email verification');
  $pdo->prepare('INSERT INTO hub_user_twofa (user_id,code_hash,code_sent_to,channel,expires_at,created) VALUES (900001, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())')->execute([password_hash('123456', PASSWORD_DEFAULT), 'auth-test@example.invalid', 'email']);
  $_SESSION[HUB_PENDING_2FA_KEY]['method'] = 'email';
  auth_mysql_check(hub_complete_twofa('123456', $error) && hub_is_logged_in(), 'Email second factor works without sending test mail');
  auth_mysql_check(!hub_verify_twofa_code(900001, '123456', $error), 'Email code cannot be consumed twice');
  hub_logout();
  // A pre-authenticator flow must never unlock enrollment, account details or QR.
  hub_start_totp_login('auth-test@example.invalid');
  auth_mysql_check(hub_pending_twofa_user() === null && hub_pending_twofa_setup_data() === null, 'Anonymous app selection does not expose setup');
  auth_mysql_check(!hub_select_twofa_method('totp', $error), 'Anonymous app selection cannot enroll a replacement authenticator');
  $pdo->exec('UPDATE hub_user_totp SET last_counter = NULL WHERE user_id = 900001');
  $directCode = hub_totp_code_for_counter($setup['secret'], (int) floor(time()/30));
  auth_mysql_check(hub_complete_totp_login($directCode, $error) && hub_is_logged_in(), 'Direct configured-app login succeeds with MySQL');
  hub_logout(); hub_start_totp_login('auth-test@example.invalid');
  auth_mysql_check(!hub_complete_totp_login($directCode, $error) && !hub_is_logged_in(), 'Direct MySQL app-code replay rejected');
  $_SESSION['hub_totp_login']['created_at'] = time() - 601;
  $freshCode = hub_totp_code_for_counter($setup['secret'], (int) floor(time()/30) + 1);
  auth_mysql_check(!hub_complete_totp_login($freshCode, $error) && !hub_is_logged_in(), 'Expired direct app-login attempt rejected');
  // An already-established session must be rejected on its next access check.
  $pdo->prepare('INSERT INTO hub_user (id,email,password_hash,display_name,role,login_enabled,archived,twofa_enabled) VALUES (900002, ?, ?, ?, ?, 1, 0, 0)')->execute(['session-test@example.invalid', password_hash('unused', PASSWORD_DEFAULT), 'Session test', 'admin']);
  hub_start_session(hub_find_user_by_email('session-test@example.invalid'));
  auth_mysql_check(hub_is_logged_in() && hub_is_admin(), 'Active MySQL session and admin privileges work');
  $oldSession = $_SESSION; $oldId = session_id();
  $_SESSION['hub_customer_override'] = 1; $_SESSION['hub_account_totp_setup'] = 1;
  $pdo->exec('UPDATE hub_user SET archived = 1 WHERE id = 900002');
  auth_mysql_check(!hub_is_logged_in() && $_SESSION === [] && session_id() !== $oldId, 'Archiving revokes established MySQL session and rotates its ID');
  $_SESSION = $oldSession;
  auth_mysql_check(hub_current_user() === null && !hub_is_admin(), 'Cached MySQL admin identity cannot survive archiving');
  $pdo->exec('UPDATE hub_user SET archived = 0 WHERE id = 900002');
  auth_mysql_check(!hub_is_logged_in(), 'Cleared session stays signed out after account restoration');
  hub_start_session(hub_find_user_by_email('session-test@example.invalid'));
  $pdo->exec('UPDATE hub_user SET login_enabled = 0 WHERE id = 900002');
  auth_mysql_check(!hub_is_logged_in() && $_SESSION === [], 'Disabling MySQL login revokes existing session');
  $pdo->exec('UPDATE hub_user SET login_enabled = 1 WHERE id = 900002');
  hub_start_session(hub_find_user_by_email('session-test@example.invalid'));
  $pdo->exec('DELETE FROM hub_user WHERE id = 900002');
  auth_mysql_check(!hub_is_logged_in(), 'Deleting MySQL account revokes existing session');
  $_SESSION[HUB_PENDING_2FA_KEY] = ['user_id' => 900001, 'email' => 'auth-test@example.invalid', 'primary_verified' => true, 'created_at' => time(), 'method' => 'email'];
  $pdo->exec('UPDATE hub_user SET archived = 1 WHERE id = 900001');
  auth_mysql_check(hub_pending_twofa_user() === null && !isset($_SESSION[HUB_PENDING_2FA_KEY]), 'Archived MySQL account cannot finish pending verification');
  echo 'PASS: ' . count($checks) . " MySQL authentication checks using temporary tables only; no emails or external requests.\n";
} finally {
  foreach (['hub_user', 'hub_user_totp', 'hub_user_twofa', 'hub_user_action_log'] as $table) $pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
  session_destroy();
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($iterator as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
  rmdir($testDirectory);
}
