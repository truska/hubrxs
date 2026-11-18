<?php
// Isolated regression checks: no production DB, network, email or limit files.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
// Child process checks response completion ordering with fake mail senders only.
if (($argv[1] ?? '') === '--mail-worker') {
  $marker = $argv[2];
  define('HUB_AUTH_RATE_LIMIT_DIR', dirname($marker) . '/worker-limits');
  ini_set('session.save_path', dirname($marker));
  session_start();
  require_once __DIR__ . '/../includes/app/auth_security.php';
  function fastcgi_finish_request(): bool {
    file_put_contents($GLOBALS['marker'], "response-finished\n", FILE_APPEND);
    return true;
  }
  function auth_worker_deliver(string $kind, string $email): array {
    if (session_status() === PHP_SESSION_ACTIVE) throw new RuntimeException('Session lock retained during delivery');
    file_put_contents($GLOBALS['marker'], 'delivery:' . $kind . "\n", FILE_APPEND);
    return ['sent' => str_starts_with($email, 'known@')];
  }
  function hub_request_magic_link(string $email): array { return auth_worker_deliver('link', $email); }
  function hub_request_password_reset(string $email): array { return auth_worker_deliver('reset', $email); }
  $_SERVER['REMOTE_ADDR'] = '192.0.2.200';
  hub_auth_defer_mail($argv[3], $argv[4]);
  if (file_exists($marker)) throw new RuntimeException('Delivery ran before response');
  echo 'If this account is eligible, a link will be sent.';
  file_put_contents($marker, "response-rendered\n");
  exit;
}
$testDirectory = sys_get_temp_dir() . '/hub-auth-test-' . bin2hex(random_bytes(8));
mkdir($testDirectory, 0700);
define('HUB_AUTH_RATE_LIMIT_DIR', $testDirectory . '/limits');
ini_set('session.save_path', $testDirectory);
session_start();
define('HUB_SESSION_KEY', 'hub_user');
define('HUB_PENDING_2FA_KEY', 'hub_pending_2fa');
define('HUB_APP_NAME', 'Test Hub');
require_once __DIR__ . '/../includes/app/auth_security.php';

// Load production function declarations without bootstrapping the real DB.
function auth_test_load_functions(string $path): void {
  $tokens = token_get_all(file_get_contents($path));
  for ($i = 0, $n = count($tokens); $i < $n; $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $code = ''; $depth = 0; $opened = false;
    for (; $i < $n; $i++) {
      $token = $tokens[$i];
      $code .= is_array($token) ? $token[1] : $token;
      if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $opened = true; }
      if ($token === '}' && --$depth === 0 && $opened) break;
    }
    eval($code);
  }
}
auth_test_load_functions(__DIR__ . '/../includes/app/auth.php');
function auth_test_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
  $GLOBALS['checks'][] = $message;
}
function hub_log_user_action(array $data): void { $GLOBALS['audit'][] = $data; }
function hub_current_request_ip(): ?string { return hub_auth_client_ip(); }
function hub_2fa_globally_enabled(): bool { return false; }
function hub_table_exists(string $table): bool {
  $stmt = $GLOBALS['pdo']->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
  $stmt->execute([$table]); return (bool) $stmt->fetchColumn();
}
function hub_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function hub_site_logo_url(): string { return '/logo.png'; }
function hub_csrf_token(): string { return 'test-csrf'; }
function hub_verify_csrf(string $token): bool { return $token === 'test-csrf'; }
function hub_flash_messages(): array { return []; }
function hub_flash(string $type, string $message): void {}
function hub_base_url(string $path = ''): string { return 'https://example.invalid/' . $path; }
function hub_email_user_name(?array $user, string $fallback = ''): string { return 'Test'; }
function hub_send_template_email(...$args): bool { throw new RuntimeException('Real email must never be sent by tests.'); }
class AuthTestRedirect extends RuntimeException {}
function hub_redirect(string $path): void { throw new AuthTestRedirect($path); }

$checks = []; $audit = [];
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$DB_OK = true;
$pdo->exec('CREATE TABLE hub_user (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, display_name TEXT, customer_id INTEGER, role TEXT, login_enabled INTEGER, archived INTEGER, twofa_enabled INTEGER, last_login_at TEXT, last_login_ip TEXT, modified TEXT)');
$pdo->exec('CREATE TABLE hub_user_totp (user_id INTEGER PRIMARY KEY, secret TEXT, enabled INTEGER, confirmed_at TEXT, last_counter INTEGER, modified TEXT)');
$insert = $pdo->prepare('INSERT INTO hub_user VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL, NULL, NULL)');
$hash = password_hash('correct-password', PASSWORD_BCRYPT, ['cost' => 10]);
foreach ([['known',1,0,0],['disabled',0,0,0],['archived',1,1,0],['unconfigured',1,0,0],['mfa',1,0,1]] as $i => [$name,$enabled,$archived,$mfa]) {
  $insert->execute([$i + 1, $name . '@example.invalid', $hash, 'Test', 'user', $enabled, $archived, $mfa]);
}
$secret = 'JBSWY3DPEHPK3PXP';
$pdo->prepare('INSERT INTO hub_user_totp VALUES (?, ?, 1, ?, NULL, NULL)')->execute([1, $secret, date('c')]);
$pdo->prepare('INSERT INTO hub_user_totp VALUES (?, ?, 0, NULL, NULL, NULL)')->execute([4, $secret]);
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
putenv('HUB_AUTH_TRUSTED_PROXIES');

try {
  // The actual page markup and redirects are compared across account states.
  $screens = [];
  foreach (['known', 'unknown', 'disabled', 'archived', 'unconfigured'] as $name) {
    $_SESSION = [];
    $_POST = ['email' => $name . '@example.invalid', 'csrf' => 'test-csrf', 'action' => 'continue'];
    ob_start();
    eval('?>' . preg_replace('/^<\?php\s*require_once[^;]+;/', '<?php', file_get_contents(__DIR__ . '/../index.php')));
    $screen = ob_get_clean();
    auth_test_check(substr_count($screen, 'data-login-method-button>') === 3, 'All three methods enabled for ' . $name);
    $screens[] = str_replace($name . '@example.invalid', 'address@example.invalid', $screen);
    foreach (['password' => 'login-password.php', 'auth_app' => 'login-auth-app.php', 'email_link' => 'login-email-sent.php'] as $action => $destination) {
      $_POST['action'] = $action;
      try {
        ob_start();
        eval('?>' . preg_replace('/^<\?php\s*require_once[^;]+;/', '<?php', file_get_contents(__DIR__ . '/../index.php')));
        throw new RuntimeException('Expected a redirect');
      } catch (AuthTestRedirect $redirect) {
        auth_test_check($redirect->getMessage() === $destination, 'Consistent ' . $action . ' redirect for ' . $name);
      } finally { ob_end_clean(); }
    }
    // No fastcgi_finish_request in CLI: no callback may deliver an email.
    auth_test_check(!isset($_SESSION[HUB_PENDING_2FA_KEY]), 'No privileged second-factor state from selecting a method');
  }
  auth_test_check(count(array_unique($screens)) === 1, 'Continue page is identical across account states');

  $resetScreens = [];
  foreach (['known', 'unknown', 'disabled', 'archived', 'unconfigured'] as $name) {
    $_SESSION = [];
    $_POST = ['email' => $name . '@example.invalid', 'csrf' => 'test-csrf'];
    ob_start();
    eval('?>' . preg_replace('/^<\?php\s*require_once[^;]+;/', '<?php', file_get_contents(__DIR__ . '/../forgot-password.php')));
    $screen = ob_get_clean();
    auth_test_check(str_contains($screen, 'If this account is eligible, a password reset link will be sent.') && !str_contains($screen, 'could not deliver'), 'Neutral reset response for ' . $name);
    $resetScreens[] = str_replace($name . '@example.invalid', 'address@example.invalid', $screen);
  }
  auth_test_check(count(array_unique($resetScreens)) === 1, 'Reset page is identical across account states');

  $workerResponses = [];
  foreach (['link', 'reset'] as $kind) {
    foreach (['known', 'unknown'] as $name) {
      $marker = $testDirectory . '/' . $kind . '-' . $name . '.events';
      $pipes = [];
      $process = proc_open([PHP_BINARY, __FILE__, '--mail-worker', $marker, $kind, $name . '@example.invalid'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
      $workerResponses[] = stream_get_contents($pipes[1]);
      $errors = stream_get_contents($pipes[2]);
      fclose($pipes[1]); fclose($pipes[2]);
      auth_test_check(proc_close($process) === 0, 'Deferred mail worker completed: ' . $errors);
      auth_test_check(file_get_contents($marker) === "response-rendered\nresponse-finished\ndelivery:" . $kind . "\n", 'Mail lookup and delivery run only after response finishes');
    }
  }
  auth_test_check(count(array_unique($workerResponses)) === 1, 'Mail success and ineligible-account responses are identical');

  // Invalid password paths match, including the disabled account's correct password.
  foreach (['known', 'unknown', 'disabled', 'archived', 'unconfigured'] as $name) {
    $_SESSION = [];
    $error = null; $started = microtime(true);
    $status = hub_login_with_password($name . '@example.invalid', $name === 'disabled' ? 'correct-password' : 'wrong', $error);
    auth_test_check($status === 'error' && $error === 'Invalid credentials.' && !hub_is_logged_in(), 'Generic password rejection for ' . $name);
    auth_test_check(microtime(true) - $started >= 0.49, 'Password timing floor for ' . $name);
  }
  foreach (['unknown', 'disabled', 'archived', 'unconfigured'] as $name) {
    $_SESSION = [];
    hub_start_totp_login($name . '@example.invalid');
    $code = hub_totp_code_for_counter($secret, (int) floor(time()/30));
    auth_test_check(!hub_complete_totp_login($code, $error) && $error === 'Unable to sign in with those details. Try again or use another method.', 'Generic authenticator rejection for ' . $name);
    auth_test_check(!hub_is_logged_in() && !hub_pending_twofa_setup_required() && hub_pending_twofa_setup_data() === null, 'No login or setup secret for ' . $name);
  }
  $_SESSION = [];
  auth_test_check(hub_login_with_password('known@example.invalid', 'correct-password', $error) === 'ok' && hub_is_logged_in(), 'Valid password signs in');
  hub_logout();
  auth_test_check(!hub_is_logged_in() && !isset($_SESSION[HUB_PENDING_2FA_KEY]), 'Logout clears authenticated and pending state');
  auth_test_check(hub_login_with_password('mfa@example.invalid', 'correct-password', $error) === '2fa' && !hub_is_logged_in() && hub_pending_twofa_user() !== null, 'Valid password preserves required second factor');
  $_SESSION[HUB_PENDING_2FA_KEY]['created_at'] = time() - 601;
  auth_test_check(hub_pending_twofa_user() === null, 'Expired pending verification rejected');
  $_SESSION = [];
  hub_start_totp_login('known@example.invalid');
  $code = hub_totp_code_for_counter($secret, (int) floor(time()/30));
  auth_test_check(hub_complete_totp_login($code, $error) && hub_is_logged_in(), 'Valid configured authenticator signs in');
  hub_logout(); hub_start_totp_login('known@example.invalid');
  auth_test_check(!hub_complete_totp_login($code, $error) && !hub_is_logged_in(), 'Authenticator code cannot be replayed');

  // Account and IP limits must survive new sessions and cannot trust spoofed headers.
  $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
  $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.22';
  auth_test_check(hub_auth_client_ip() === '198.51.100.1', 'Untrusted forwarded address ignored');
  putenv('HUB_AUTH_TRUSTED_PROXIES=198.51.100.1,198.51.100.2');
  $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.22, 198.51.100.2';
  auth_test_check(hub_auth_client_ip() === '203.0.113.22', 'Configured proxy chain resolves client');
  putenv('HUB_AUTH_TRUSTED_PROXIES');
  for ($i=0; $i<10; $i++) auth_test_check(hub_auth_rate_limit('login', 'limited@example.invalid'), 'Allowed login attempt ' . ($i+1));
  $_SESSION = [];
  auth_test_check(!hub_auth_rate_limit('login', 'LIMITED@example.invalid'), 'Address limit survives session reset and case changes');
  $_SERVER['REMOTE_ADDR'] = '198.51.100.3';
  auth_test_check(!hub_auth_rate_limit('login', 'limited@example.invalid'), 'Address limit survives IP change');
  $retry = hub_auth_login_retry_after('limited@example.invalid');
  auth_test_check($retry > 0 && $retry <= 900, 'Cooldown reports the remaining server window');
  $snapshots = [];
  foreach (glob(HUB_AUTH_RATE_LIMIT_DIR . '/*.json') as $path) $snapshots[$path] = file_get_contents($path);
  $_SESSION = [];
  hub_auth_login_retry_after('LIMITED@example.invalid');
  foreach ($snapshots as $path => $contents) auth_test_check(file_get_contents($path) === $contents, 'Reading cooldown does not consume or extend a counter');
  auth_test_check(hub_login_with_password('limited@example.invalid', 'correct-password', $error) === 'error' && $error === 'Too many attempts. Please try again later.', 'Submitting directly still enforces server limit');
  foreach (['login-password.php', 'login-auth-app.php'] as $page) {
    $_SESSION = ['hub_login_email' => 'limited@example.invalid', 'hub_totp_login' => ['email' => 'limited@example.invalid', 'created_at' => time()]];
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = [];
    ob_start();
    eval('?>' . preg_replace('/^<\?php\s*require_once[^;]+;/', '<?php', file_get_contents(__DIR__ . '/../' . $page)));
    $html = ob_get_clean();
    auth_test_check(str_contains($html, 'data-auth-submit disabled') && str_contains($html, 'Too many attempts. Try again in') && str_contains($html, '/js/auth-cooldown.js'), 'Refresh renders disabled Sign in and cooldown on ' . $page);
    auth_test_check(str_contains($html, '>Back to sign-in</a>') && !str_contains($html, '>Use another method</a>'), 'Clear return-to-email link on ' . $page);
  }
  // Seed only temporary counters to check longer IP windows and exact expiry.
  function auth_test_seed_limit(string $key, int $count, int $until): void {
    $hash = hash('sha256', $key);
    $path = HUB_AUTH_RATE_LIMIT_DIR . '/' . (hexdec(substr($hash,0,2)) % 64) . '.json';
    $entries = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    $entries[$hash] = ['count' => $count, 'until' => $until];
    file_put_contents($path, json_encode($entries));
  }
  auth_test_seed_limit('login:email:cooldown@example.invalid', 10, time() + 60);
  auth_test_seed_limit('login:ip:198.51.100.3', 100, time() + 300);
  $retry = hub_auth_login_retry_after('cooldown@example.invalid');
  auth_test_check($retry >= 299 && $retry <= 300, 'Longer IP cooldown controls re-enable time');
  auth_test_seed_limit('login:email:cooldown@example.invalid', 10, time() - 1);
  auth_test_seed_limit('login:ip:198.51.100.3', 100, time() - 1);
  auth_test_check(hub_auth_login_retry_after('cooldown@example.invalid') === 0, 'Expired cooldown enables fresh attempts');
  auth_test_check(hub_auth_rate_limit('login', 'cooldown@example.invalid'), 'Server accepts another attempt after expiry');
  $_SERVER['REQUEST_METHOD'] = 'POST';
  for ($i=0; $i<3; $i++) auth_test_check(hub_auth_rate_limit('mail', 'mail@example.invalid'), 'Allowed email request ' . ($i+1));
  auth_test_check(!hub_auth_rate_limit('mail', 'mail@example.invalid'), 'Email requests limited');
  $_SERVER['REMOTE_ADDR'] = '198.51.100.4';
  for ($i=0; $i<20; $i++) auth_test_check(hub_auth_rate_limit('mail', 'different' . $i . '@example.invalid'), 'Allowed distinct address request ' . ($i+1));
  auth_test_check(!hub_auth_rate_limit('mail', 'next@example.invalid'), 'IP limit covers changing addresses');

  $bucketDir = $testDirectory . '/buckets'; mkdir($bucketDir);
  $key = 'expiry'; $keyHash = hash('sha256', $key);
  $file = $bucketDir . '/' . (hexdec(substr($keyHash,0,2)) % 64) . '.json';
  file_put_contents($file, json_encode([$keyHash=>['count'=>99,'until'=>time()-1]]));
  auth_test_check(hub_auth_consume_bucket($bucketDir, $key, 1, 900), 'Expired limit becomes available');
  auth_test_check(hub_auth_bucket_retry_after($bucketDir, $key, 1) > 0, 'Consumed final allowance shows cooldown immediately');
  file_put_contents($file, '{broken');
  auth_test_check(hub_auth_bucket_retry_after($bucketDir, $key, 1) === 900, 'Corrupt cooldown data keeps the button blocked');
  auth_test_check(!hub_auth_consume_bucket($bucketDir, $key, 1, 900), 'Corrupt limit storage fails closed');
  auth_test_check(!hub_auth_consume_bucket($testDirectory . '/missing', 'key', 1, 900), 'Missing storage fails closed');
  auth_test_check(count(glob(HUB_AUTH_RATE_LIMIT_DIR . '/*.json')) <= 64, 'Rate-limit file count is bounded');
  if (function_exists('pcntl_fork')) {
    $children = [];
    for ($i=0;$i<12;$i++) {
      $pid=pcntl_fork();
      if ($pid === 0) exit(hub_auth_consume_bucket($bucketDir, 'parallel', 5, 900) ? 0 : 1);
      if ($pid < 0) throw new RuntimeException('Fork failed');
      $children[]=$pid;
    }
    $allowed=0;
    foreach ($children as $pid) { pcntl_waitpid($pid,$status); if(pcntl_wexitstatus($status)===0) $allowed++; }
    auth_test_check($allowed===5, 'Concurrent requests cannot exceed a shared limit');
  }
  // Simulate subsequent requests from existing sessions after account changes.
  foreach (['user', 'admin'] as $offset => $role) {
    $id = 90 + $offset;
    $address = 'revoke-' . $role . '@example.invalid';
    $pdo->prepare('INSERT INTO hub_user (id,email,password_hash,display_name,role,login_enabled,archived,twofa_enabled) VALUES (?, ?, ?, ?, ?, 1, 0, 0)')->execute([$id, $address, $hash, 'Revocation test', $role]);
    hub_start_session(hub_find_user_by_email($address));
    auth_test_check(hub_is_logged_in(), 'Active ' . $role . ' session still works');
    $_SESSION['hub_customer_override'] = 123;
    $_SESSION['hub_account_totp_setup'] = 1;
    $_SESSION['hub_csrf'] = 'old-csrf';
    $oldSession = $_SESSION; $oldId = session_id();
    $pdo->exec('UPDATE hub_user SET archived = 1 WHERE id = ' . $id);
    auth_test_check(hub_current_user() === null && $_SESSION === [], 'Archiving clears all ' . $role . ' session state');
    auth_test_check(session_id() !== $oldId, 'Revoked ' . $role . ' session ID is rotated');
    $_SESSION = $oldSession;
    auth_test_check(!hub_is_logged_in() && !hub_is_admin(), 'Stale ' . $role . ' session cannot pass signed-in or role checks');
    foreach (['hub_require_login', 'hub_require_admin', 'hub_require_manager', 'hub_require_super_admin', 'hub_require_developer'] as $guard) {
      $_SESSION = $oldSession;
      try { $guard(); throw new RuntimeException('Archived session passed ' . $guard); }
      catch (AuthTestRedirect $redirect) { auth_test_check($redirect->getMessage() === '/index.php?login=1', 'Archived ' . $role . ' session rejected by ' . $guard); }
    }
    $pdo->exec('UPDATE hub_user SET archived = 0 WHERE id = ' . $id);
    auth_test_check(!hub_is_logged_in(), 'Unarchiving does not restore a cleared ' . $role . ' session');
  }
  $active = hub_find_user_by_email('revoke-admin@example.invalid');
  hub_start_session($active);
  $pdo->exec("UPDATE hub_user SET role = 'user' WHERE id = 91");
  auth_test_check(!hub_is_admin() && hub_current_user()['role'] === 'user', 'Role changes replace stale cached privileges');
  $pdo->exec('UPDATE hub_user SET login_enabled = 0 WHERE id = 91');
  auth_test_check(!hub_is_logged_in() && $_SESSION === [], 'Disabling an account revokes an existing session');
  $pdo->exec('UPDATE hub_user SET login_enabled = 1 WHERE id = 91');
  hub_start_session(hub_find_user_by_email('revoke-admin@example.invalid'));
  $cachedSession = $_SESSION;
  $DB_OK = false;
  auth_test_check(!hub_is_logged_in() && $_SESSION === [], 'Unavailable database cannot authorize cached identity');
  $DB_OK = true;
  $_SESSION = $cachedSession;
  $pdo->exec('ALTER TABLE hub_user RENAME TO hub_user_unavailable');
  auth_test_check(!hub_is_logged_in() && $_SESSION === [], 'Database query failure clears cached authentication');
  $pdo->exec('ALTER TABLE hub_user_unavailable RENAME TO hub_user');
  $_SESSION = $cachedSession;
  $pdo->exec('DELETE FROM hub_user WHERE id = 91');
  auth_test_check(!hub_is_logged_in() && $_SESSION === [], 'Deleted account cannot retain a session');
  $_SESSION[HUB_PENDING_2FA_KEY] = ['user_id' => 90, 'email' => 'revoke-user@example.invalid', 'primary_verified' => true, 'created_at' => time(), 'method' => 'email'];
  $pdo->exec('UPDATE hub_user SET archived = 1 WHERE id = 90');
  auth_test_check(hub_pending_twofa_user() === null && !isset($_SESSION[HUB_PENDING_2FA_KEY]), 'Archiving also clears pending verification');
  session_destroy();
  echo 'PASS: ' . count($checks) . " isolated authentication checks; no production DB writes, email or external requests.\n";
} finally {
  if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($iterator as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
  rmdir($testDirectory);
}
