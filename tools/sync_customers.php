<?php
$isCli = (php_sapi_name() === 'cli');
if ($isCli && session_status() === PHP_SESSION_NONE) {
  ini_set('session.save_path', sys_get_temp_dir());
}

require_once __DIR__ . '/../includes/app/auth.php';

if (!$isCli) {
  http_response_code(403);
  echo 'CLI only.';
  exit;
}

if (!$DB_OK || !($pdo instanceof PDO)) {
  $msg = 'Database not available.';
  if ($isCli) {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
  }
  http_response_code(500);
  echo hub_h($msg);
  exit;
}

function hub_sync_customer_admin_action(string $code, int $customerId): void {
  global $pdo;

  if (!hub_table_exists('hub_admin_action')) {
    return;
  }

  $stmt = $pdo->prepare(
    'INSERT INTO hub_admin_action
      (action_key, action_type, entity_type, entity_id, title, message, status, priority, source, created, modified)
     VALUES
      (:action_key, :action_type, :entity_type, :entity_id, :title, :message, :status, :priority, :source, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      entity_id = VALUES(entity_id),
      title = VALUES(title),
      message = VALUES(message),
      status = IF(status = "complete", status, VALUES(status)),
      modified = NOW()'
  );
  $stmt->execute([
    ':action_key' => 'review_customer:' . $code,
    ':action_type' => 'review_customer',
    ':entity_type' => 'customer',
    ':entity_id' => $customerId,
    ':title' => 'Review customer details: ' . $code,
    ':message' => 'Customer reference ' . $code . ' was found in imported data. Please confirm the customer record and user assignment.',
    ':status' => 'open',
    ':priority' => 'normal',
    ':source' => 'customer_sync',
  ]);
}

function hub_sync_json_value(array $raw, string $wantedKey): string {
  foreach ($raw as $key => $value) {
    if (mb_strtolower((string) $key) === mb_strtolower($wantedKey)) {
      return trim((string) $value);
    }
  }
  return '';
}

function hub_sync_table_column_exists(string $table, string $column): bool {
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
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE :column');
    $stmt->execute([':column' => $column]);
    $cache[$key] = (bool) $stmt->fetchColumn();
  } catch (PDOException $e) {
    $cache[$key] = false;
  }

  return $cache[$key];
}

// Load existing customer codes.
$existing = [];
if (hub_table_exists('hub_customer')) {
  $stmt = $pdo->query('SELECT code FROM hub_customer WHERE code IS NOT NULL AND code <> ""');
  $existingCodes = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
  foreach ($existingCodes as $code) {
    $trimmed = trim((string) $code);
    if ($trimmed !== '') {
      $existing[$trimmed] = true;
    }
  }
}
$distinctCodes = [];

if (hub_table_exists('hub_so_live') && hub_sync_table_column_exists('hub_so_live', 'customer_ref')) {
  $stmtPortalRefs = $pdo->query(
    "SELECT DISTINCT TRIM(customer_ref) AS code
     FROM hub_so_live
     WHERE customer_ref IS NOT NULL
       AND TRIM(customer_ref) <> ''"
  );
  foreach ($stmtPortalRefs ? ($stmtPortalRefs->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $code) {
    $trimmed = trim((string) $code);
    if ($trimmed !== '') {
      $distinctCodes[$trimmed] = true;
    }
  }
}

if (hub_table_exists('hub_so_live')) {
  $stmtPortalLines = $pdo->query('SELECT raw_json FROM hub_so_live WHERE raw_json IS NOT NULL');
  foreach ($stmtPortalLines ? ($stmtPortalLines->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
    $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
    if (!is_array($raw)) {
      continue;
    }
    $code = hub_sync_json_value($raw, 'description_2');
    if ($code !== '') {
      $distinctCodes[$code] = true;
    }
  }
}

$inserted = 0;
$skipped = 0;

if ($distinctCodes) {
  $ins = $pdo->prepare('INSERT INTO hub_customer (name, code, notes, archived, created, modified) VALUES (:name, :code, :notes, 0, NOW(), NOW())');
  foreach (array_keys($distinctCodes) as $code) {
    $trimmed = trim((string) $code);
    if ($trimmed === '') {
      continue;
    }
    if (isset($existing[$trimmed])) {
      $skipped++;
      continue;
    }

    try {
      $ins->execute([
        ':name' => $trimmed,
        ':code' => $trimmed,
        ':notes' => 'Created automatically from imported customer reference. Temporary source may be description_2 until a stable customer ID is supplied.',
      ]);
      $newId = (int) $pdo->lastInsertId();
      hub_sync_customer_admin_action($trimmed, $newId);
      $inserted++;
      $existing[$trimmed] = true;
    } catch (PDOException $e) {
      $skipped++;
    }
  }
}

$msg = "Inserted {$inserted} customers. Skipped {$skipped}. Sources checked: SO Portal Lines customer_ref and live JSON description_2.";

if ($isCli) {
  echo $msg . PHP_EOL;
  exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sync Customers</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand">Admin</p>
      <h1>Sync Customers from Imports</h1>
      <div class="alert success"><?php echo hub_h($msg); ?></div>
      <div class="links">
        <a href="/dashboard.php">Back to dashboard</a>
      </div>
    </div>
  </div>
</body>
</html>
