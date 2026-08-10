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

if (!hub_table_exists('hub_import_source') || !hub_table_exists('hub_so_raw') || !hub_table_exists('hub_import_fetch')) {
  $msg = 'SO raw import tables are not ready.';
  if ($isCli) {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
  }
  http_response_code(400);
  echo hub_h($msg);
  exit;
}

if (!hub_table_exists('hub_so_live') || !hub_table_exists('hub_customer_mapping')) {
  $msg = 'Live schema is not ready. Run /tools/install_hub_schema.php?run=1 first.';
  if ($isCli) {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
  }
  http_response_code(400);
  echo hub_h($msg);
  exit;
}

$stmtSources = $pdo->query(
  "SELECT DISTINCT s.id, s.name, s.import_key, s.handler
   FROM hub_import_source s
   INNER JOIN hub_so_raw r ON r.source_id = s.id
   WHERE s.archived = 0
     AND (
       s.handler = 'so_portal_lines'
       OR r.raw_json LIKE '%description_2%'
     )
   ORDER BY s.id ASC"
);
$sources = $stmtSources ? ($stmtSources->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$processed = 0;
$sourceSummaries = [];

foreach ($sources as $source) {
  $sourceId = (int) $source['id'];
  $stmtFetch = $pdo->prepare(
    "SELECT id
     FROM hub_import_fetch
     WHERE source_id = :source_id
       AND status = 'complete'
       AND skipped = 0
     ORDER BY id DESC
     LIMIT 1"
  );
  $stmtFetch->execute([':source_id' => $sourceId]);
  $fetchId = (int) $stmtFetch->fetchColumn();
  if ($fetchId <= 0) {
    $sourceSummaries[] = [
      'source' => (string) ($source['name'] ?? ('Source #' . $sourceId)),
      'rows' => 0,
      'processed' => 0,
      'note' => 'No completed fetch found',
    ];
    continue;
  }

  $stmtRows = $pdo->prepare(
    'SELECT raw_json
     FROM hub_so_raw
     WHERE source_id = :source_id
     ORDER BY row_index ASC'
  );
  $stmtRows->execute([':source_id' => $sourceId]);

  $rows = [];
  foreach ($stmtRows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $payloadRow) {
    $decoded = json_decode((string) ($payloadRow['raw_json'] ?? ''), true);
    if (is_array($decoded)) {
      $rows[] = $decoded;
    }
  }

  $sourceProcessed = hub_import_apply_so_portal_lines($sourceId, $fetchId, $rows);
  $processed += $sourceProcessed;
  $sourceSummaries[] = [
    'source' => (string) ($source['name'] ?? ('Source #' . $sourceId)),
    'rows' => count($rows),
    'processed' => $sourceProcessed,
    'note' => '',
  ];
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

$msg = "Rebuilt {$processed} live SO Portal Lines from staged import rows.";

if ($isCli) {
  echo $msg . PHP_EOL;
  foreach ($sourceSummaries as $summary) {
    echo $summary['source'] . ': rows=' . $summary['rows'] . ' processed=' . $summary['processed'] . ($summary['note'] ? ' ' . $summary['note'] : '') . PHP_EOL;
  }
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
  <title>Rebuild Live SO Portal Lines</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand">Admin</p>
      <h1>Rebuild Live SO Portal Lines</h1>
      <div class="alert success"><?php echo hub_h($msg); ?></div>

      <h2>Sources</h2>
      <div class="import-data-table-wrap">
        <table class="table table-dark table-striped table-hover table-sm align-middle import-data-table">
          <thead>
            <tr>
              <th>Source</th>
              <th>Staged Rows</th>
              <th>Processed</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sourceSummaries as $summary): ?>
              <tr>
                <td><?php echo hub_h((string) $summary['source']); ?></td>
                <td><?php echo number_format((int) $summary['rows']); ?></td>
                <td><?php echo number_format((int) $summary['processed']); ?></td>
                <td><?php echo hub_h((string) $summary['note']); ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($sourceSummaries)): ?>
              <tr><td colspan="4">No active SO Portal Lines import source found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if (!empty($summaryRows)): ?>
        <h2>Live Rows by Customer</h2>
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
