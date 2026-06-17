<?php
require_once __DIR__ . '/../includes/app/bootstrap.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
  hub_require_admin();
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

if (!hub_table_exists('hub_sales_order')) {
  $msg = 'hub_sales_order table not found.';
  if ($isCli) {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
  }
  http_response_code(400);
  echo hub_h($msg);
  exit;
}

$stmtDistinct = $pdo->query(
  "SELECT DISTINCT TRIM(customer_code) AS code
   FROM hub_sales_order
   WHERE customer_code IS NOT NULL
     AND TRIM(customer_code) <> ''"
);
$distinctCodes = $stmtDistinct ? $stmtDistinct->fetchAll(PDO::FETCH_COLUMN) : [];

$inserted = 0;
$skipped = 0;

if ($distinctCodes) {
  $ins = $pdo->prepare('INSERT INTO hub_customer (name, code, archived, created, modified) VALUES (:name, :code, 0, NOW(), NOW())');
  foreach ($distinctCodes as $code) {
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
      ]);
      $inserted++;
      $existing[$trimmed] = true;
    } catch (PDOException $e) {
      $skipped++;
    }
  }
}

$msg = "Inserted {$inserted} customers. Skipped {$skipped}.";

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
      <h1>Sync Customers from Sales Orders</h1>
      <div class="alert success"><?php echo hub_h($msg); ?></div>
      <div class="links">
        <a href="/dashboard.php">Back to dashboard</a>
      </div>
    </div>
  </div>
</body>
</html>
