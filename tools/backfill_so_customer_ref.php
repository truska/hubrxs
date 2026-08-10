<?php
$isCli = (php_sapi_name() === 'cli');
if ($isCli && session_status() === PHP_SESSION_NONE) {
  ini_set('session.save_path', sys_get_temp_dir());
}

require_once __DIR__ . '/../includes/app/auth.php';

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

if (!hub_table_exists('hub_so_live')) {
  $msg = 'hub_so_live table not found.';
  if ($isCli) {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
  }
  http_response_code(400);
  echo hub_h($msg);
  exit;
}

try {
  $pdo->exec('ALTER TABLE hub_so_live ADD COLUMN customer_ref VARCHAR(255) NULL AFTER customer_name');
} catch (PDOException $e) {
  $alreadyApplied = $e->getCode() === '42S21' || stripos($e->getMessage(), 'Duplicate column') !== false;
  if (!$alreadyApplied) {
    throw $e;
  }
}

try {
  $pdo->exec('ALTER TABLE hub_so_live ADD KEY idx_hub_so_live_customer_ref (customer_ref)');
} catch (PDOException $e) {
  $alreadyApplied = stripos($e->getMessage(), 'Duplicate key name') !== false;
  if (!$alreadyApplied) {
    throw $e;
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

$stmtRows = $pdo->query('SELECT id, raw_json FROM hub_so_live WHERE raw_json IS NOT NULL');
$stmtUpdate = $pdo->prepare(
  'UPDATE hub_so_live
   SET
    order_nbr = :order_nbr,
    shipment_nbr = :shipment_nbr,
    customer_name = :customer_name,
    customer_ref = :customer_ref,
    project = :project,
    description = :description,
    external_reference = :external_reference,
    status = :status,
    line_description = :line_description,
    ship_via = :ship_via,
    tracking_number = :tracking_number,
    lot_serial_nbr = :lot_serial_nbr,
    order_type = :order_type,
    base_type = :base_type,
    project_id = :project_id,
    line_nbr = :line_nbr,
    allocation_id = :allocation_id,
    modified = NOW()
   WHERE id = :id
   LIMIT 1'
);

$updated = 0;
$skipped = 0;

foreach ($stmtRows ? ($stmtRows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
  $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
  if (!is_array($raw)) {
    $skipped++;
    continue;
  }

  $customerRef = hub_backfill_json_value($raw, 'description_2');

  if ($customerRef === '') {
    $skipped++;
    continue;
  }

  $stmtUpdate->execute([
    ':order_nbr' => hub_backfill_json_value($raw, 'OrderNbr'),
    ':shipment_nbr' => hub_backfill_json_value($raw, 'ShipmentNbr'),
    ':customer_name' => hub_backfill_json_value($raw, 'CustomerName'),
    ':customer_ref' => $customerRef,
    ':project' => hub_backfill_json_value($raw, 'Project'),
    ':description' => hub_backfill_json_value($raw, 'Description'),
    ':external_reference' => hub_backfill_json_value($raw, 'ExternalReference'),
    ':status' => hub_backfill_json_value($raw, 'Status'),
    ':line_description' => hub_backfill_json_value($raw, 'LineDescription'),
    ':ship_via' => hub_backfill_json_value($raw, 'ShipVia'),
    ':tracking_number' => hub_backfill_json_value($raw, 'TrackingNumber'),
    ':lot_serial_nbr' => hub_backfill_json_value($raw, 'LotSerialNbr'),
    ':order_type' => hub_backfill_json_value($raw, 'OrderType'),
    ':base_type' => hub_backfill_json_value($raw, 'BaseType'),
    ':project_id' => hub_backfill_json_value($raw, 'ProjectID'),
    ':line_nbr' => hub_backfill_json_value($raw, 'LineNbr'),
    ':allocation_id' => hub_backfill_json_value($raw, 'AllocationID'),
    ':id' => (int) $row['id'],
  ]);
  $updated++;
}

$msg = "Backfilled {$updated} SO Portal Lines customer_ref values. Skipped {$skipped}.";
$summaryRows = [];

$stmtSummary = $pdo->query(
  'SELECT
    COALESCE(NULLIF(TRIM(customer_ref), ""), "(blank)") AS customer_ref,
    COALESCE(NULLIF(TRIM(status), ""), "(blank)") AS status,
    COUNT(*) AS total
   FROM hub_so_live
   GROUP BY COALESCE(NULLIF(TRIM(customer_ref), ""), "(blank)"), COALESCE(NULLIF(TRIM(status), ""), "(blank)")
   ORDER BY total DESC, customer_ref ASC, status ASC
   LIMIT 30'
);
$summaryRows = $stmtSummary ? ($stmtSummary->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

if ($isCli) {
  echo $msg . PHP_EOL;
  foreach ($summaryRows as $summaryRow) {
    echo $summaryRow['customer_ref'] . ' / ' . $summaryRow['status'] . ': ' . $summaryRow['total'] . PHP_EOL;
  }
  exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Backfill SO Customer Ref</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand">Admin</p>
      <h1>Backfill SO Customer Ref</h1>
      <div class="alert success"><?php echo hub_h($msg); ?></div>
      <?php if (!empty($summaryRows)): ?>
        <h2>Current SO Portal Line Summary</h2>
        <div class="import-data-table-wrap">
          <table class="table table-dark table-striped table-hover table-sm align-middle import-data-table">
            <thead>
              <tr>
                <th>Customer Ref</th>
                <th>Status</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($summaryRows as $summaryRow): ?>
                <tr>
                  <td><?php echo hub_h((string) $summaryRow['customer_ref']); ?></td>
                  <td><?php echo hub_h((string) $summaryRow['status']); ?></td>
                  <td><?php echo number_format((int) $summaryRow['total']); ?></td>
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
