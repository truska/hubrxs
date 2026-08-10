<?php
$isCli = (php_sapi_name() === 'cli');
if ($isCli && session_status() === PHP_SESSION_NONE) {
  ini_set('session.save_path', sys_get_temp_dir());
}

require_once __DIR__ . '/../includes/app/imports/remote_sources.php';

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

function hub_backfill_column_exists(string $table, string $column): bool {
  global $pdo;

  try {
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE :column');
    $stmt->execute([':column' => $column]);
    return (bool) $stmt->fetchColumn();
  } catch (PDOException $e) {
    return false;
  }
}

function hub_backfill_json_value(array $raw, string $wantedKey): string {
  foreach ($raw as $key => $value) {
    if (mb_strtolower((string) $key) === mb_strtolower($wantedKey)) {
      return trim((string) $value);
    }
  }
  return '';
}

$requirementsOk = hub_table_exists('hub_customer')
  && hub_table_exists('hub_customer_mapping')
  && hub_table_exists('hub_customer_mapping')
  && hub_table_exists('hub_so_live')
  && hub_backfill_column_exists('hub_so_live', 'customer_id')
  && hub_backfill_column_exists('hub_so_live', 'source_customer_value');

if (!$requirementsOk) {
  $msg = 'Schema is not ready. Run /tools/install_hub_schema.php?run=1 first.';
  if ($isCli) {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
  }
  http_response_code(400);
  echo hub_h($msg);
  exit;
}

$updatedSo = 0;
$skippedSo = 0;
$updatedInventory = 0;
$skippedInventory = 0;

$stmtSoRows = $pdo->query('SELECT id, raw_json FROM hub_so_live WHERE raw_json IS NOT NULL');
$stmtSoUpdate = $pdo->prepare(
  'UPDATE hub_so_live
   SET customer_id = :customer_id,
       source_customer_field = :source_customer_field,
       source_customer_value = :source_customer_value,
       customer_ref = :source_customer_value,
       modified = NOW()
   WHERE id = :id
   LIMIT 1'
);

foreach ($stmtSoRows ? ($stmtSoRows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
  $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
  if (!is_array($raw)) {
    $skippedSo++;
    continue;
  }

  $sourceField = 'customer_name';
  $sourceValue = hub_backfill_json_value($raw, $sourceField);
  if ($sourceValue === '') {
    $sourceValue = hub_backfill_json_value($raw, 'CustomerName');
  }
  $customerId = hub_import_customer_id_for_mapping('so_portal_lines', $sourceField, $sourceValue);
  if (!$customerId) {
    $skippedSo++;
    continue;
  }

  $stmtSoUpdate->execute([
    ':customer_id' => $customerId,
    ':source_customer_field' => $sourceField,
    ':source_customer_value' => $sourceValue,
    ':id' => (int) $row['id'],
  ]);
  $updatedSo++;
}

if (hub_table_exists('hub_inventory_live') && hub_backfill_column_exists('hub_inventory_live', 'customer_id')) {
  $stmtInventoryRows = $pdo->query('SELECT id, raw_json FROM hub_inventory_live WHERE raw_json IS NOT NULL');
  $stmtInventoryUpdate = $pdo->prepare(
    'UPDATE hub_inventory_live
     SET customer_id = :customer_id,
         source_customer_value = :source_customer_value,
         modified = NOW()
     WHERE id = :id
     LIMIT 1'
  );

  foreach ($stmtInventoryRows ? ($stmtInventoryRows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
    $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
    if (!is_array($raw)) {
      $skippedInventory++;
      continue;
    }

    $sourceField = 'CustomerName';
    $sourceValue = hub_backfill_json_value($raw, $sourceField);
    $customerId = hub_portal_inventory_customer_id_for_value($sourceValue);
    if (!$customerId) {
      $skippedInventory++;
      continue;
    }

    $stmtInventoryUpdate->execute([
      ':customer_id' => $customerId,
      ':source_customer_value' => $sourceValue,
      ':id' => (int) $row['id'],
    ]);
    $updatedInventory++;
  }
}

$summaryRows = [];
$stmtSummary = $pdo->query(
  'SELECT c.id, c.name, COUNT(l.id) AS so_lines
   FROM hub_customer c
   LEFT JOIN hub_so_live l ON l.customer_id = c.id
   GROUP BY c.id, c.name
   HAVING so_lines > 0
   ORDER BY so_lines DESC, c.name ASC
   LIMIT 30'
);
$summaryRows = $stmtSummary ? ($stmtSummary->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$msg = "Updated {$updatedSo} SO Portal Lines and {$updatedInventory} inventory rows. Skipped {$skippedSo} SO rows and {$skippedInventory} inventory rows.";

if ($isCli) {
  echo $msg . PHP_EOL;
  foreach ($summaryRows as $summaryRow) {
    echo '#' . $summaryRow['id'] . ' ' . $summaryRow['name'] . ': ' . $summaryRow['so_lines'] . PHP_EOL;
  }
  exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Backfill Live Customer IDs</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand">Admin</p>
      <h1>Backfill Live Customer IDs</h1>
      <div class="alert success"><?php echo hub_h($msg); ?></div>
      <?php if (!empty($summaryRows)): ?>
        <div class="import-data-table-wrap">
          <table class="table table-dark table-striped table-hover table-sm align-middle import-data-table">
            <thead>
              <tr>
                <th>Customer ID</th>
                <th>Customer</th>
                <th>SO Lines</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($summaryRows as $summaryRow): ?>
                <tr>
                  <td><?php echo (int) $summaryRow['id']; ?></td>
                  <td><?php echo hub_h((string) $summaryRow['name']); ?></td>
                  <td><?php echo number_format((int) $summaryRow['so_lines']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="links">
        <a href="/dashboard.php">Back to dashboard</a>
      </div>
    </div>
  </div>
</body>
</html>
