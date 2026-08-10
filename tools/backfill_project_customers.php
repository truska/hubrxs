<?php
$isCli = (php_sapi_name() === 'cli');
if ($isCli && session_status() === PHP_SESSION_NONE) {
  ini_set('session.save_path', sys_get_temp_dir());
}

require_once __DIR__ . '/../includes/app/auth.php';

if (!$isCli) {
  hub_require_login();
  if (!hub_is_super_admin() && !hub_is_developer()) {
    http_response_code(403);
    echo 'Super admins or developers only.';
    exit;
  }
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

foreach (['hub_project', 'hub_so_live'] as $table) {
  if (!hub_table_exists($table)) {
    $msg = $table . ' table not found.';
    if ($isCli) {
      fwrite(STDERR, $msg . PHP_EOL);
      exit(1);
    }
    http_response_code(400);
    echo hub_h($msg);
    exit;
  }
}

function hub_backfill_project_customer_admin_action(string $projectCode, int $projectId, string $message): void {
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
    ':action_key' => 'review_project_customer:' . $projectCode,
    ':action_type' => 'review_project_customer',
    ':entity_type' => 'project',
    ':entity_id' => $projectId,
    ':title' => 'Review project customer: ' . $projectCode,
    ':message' => $message,
    ':status' => 'open',
    ':priority' => 'normal',
    ':source' => 'project_customer_backfill',
  ]);
}

$stmtProjects = $pdo->query(
  'SELECT
      TRIM(project) AS project_code,
      COUNT(*) AS row_count,
      COUNT(DISTINCT customer_id) AS customer_count,
      MIN(customer_id) AS customer_id
   FROM hub_so_live
   WHERE project IS NOT NULL
     AND TRIM(project) <> ""
     AND customer_id IS NOT NULL
     AND customer_id > 0
     AND archived = 0
   GROUP BY TRIM(project)
   ORDER BY project_code ASC'
);
$projectRows = $stmtProjects ? ($stmtProjects->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$stmtFindProject = $pdo->prepare('SELECT id, customer_id FROM hub_project WHERE code = :code LIMIT 1');
$stmtUpdateProject = $pdo->prepare('UPDATE hub_project SET customer_id = :customer_id, modified = NOW() WHERE id = :id AND customer_id IS NULL LIMIT 1');

$updated = 0;
$alreadySet = 0;
$missingProjects = 0;
$conflicts = [];
$unambiguous = 0;

foreach ($projectRows as $row) {
  $projectCode = trim((string) ($row['project_code'] ?? ''));
  if ($projectCode === '') {
    continue;
  }

  $customerCount = (int) ($row['customer_count'] ?? 0);
  $customerId = (int) ($row['customer_id'] ?? 0);
  $rowCount = (int) ($row['row_count'] ?? 0);

  $stmtFindProject->execute([':code' => $projectCode]);
  $project = $stmtFindProject->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$project) {
    $missingProjects++;
    $conflicts[] = [
      'project_code' => $projectCode,
      'status' => 'Missing project record',
      'detail' => 'Found in SO live rows but not in hub_project.',
      'row_count' => $rowCount,
    ];
    continue;
  }

  $projectId = (int) $project['id'];
  $existingCustomerId = isset($project['customer_id']) ? (int) $project['customer_id'] : 0;

  if ($customerCount !== 1 || $customerId <= 0) {
    $detail = 'Project appears against ' . $customerCount . ' customers in SO live data.';
    $conflicts[] = [
      'project_code' => $projectCode,
      'status' => 'Ambiguous customer',
      'detail' => $detail,
      'row_count' => $rowCount,
    ];
    hub_backfill_project_customer_admin_action($projectCode, $projectId, $detail);
    continue;
  }

  $unambiguous++;
  if ($existingCustomerId <= 0) {
    $stmtUpdateProject->execute([':customer_id' => $customerId, ':id' => $projectId]);
    if ($stmtUpdateProject->rowCount() > 0) {
      $updated++;
    }
    continue;
  }

  if ($existingCustomerId === $customerId) {
    $alreadySet++;
    continue;
  }

  $detail = 'Project is assigned to customer ID ' . $existingCustomerId . ' but SO live data points to customer ID ' . $customerId . '.';
  $conflicts[] = [
    'project_code' => $projectCode,
    'status' => 'Existing assignment differs',
    'detail' => $detail,
    'row_count' => $rowCount,
  ];
  hub_backfill_project_customer_admin_action($projectCode, $projectId, $detail);
}

$stmtBlank = $pdo->query('SELECT COUNT(*) FROM hub_so_live WHERE (project IS NULL OR TRIM(project) = "") AND archived = 0');
$blankProjectRows = $stmtBlank ? (int) $stmtBlank->fetchColumn() : 0;

$msg = 'Project customer backfill checked ' . count($projectRows) . ' distinct SO projects. Updated ' . $updated . ', already set ' . $alreadySet . ', unambiguous ' . $unambiguous . ', conflicts ' . count($conflicts) . ', missing projects ' . $missingProjects . ', blank SO project rows ' . $blankProjectRows . '.';

if ($isCli) {
  echo $msg . PHP_EOL;
  foreach ($conflicts as $conflict) {
    echo $conflict['project_code'] . ' / ' . $conflict['status'] . ' / ' . $conflict['detail'] . ' / rows ' . $conflict['row_count'] . PHP_EOL;
  }
  exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Backfill Project Customers</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
  <?php echo hub_admin_header(hub_is_developer() ? 'dev' : 'super'); ?>
  <div class="stack">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand">Tools</p>
          <h1>Backfill Project Customers</h1>
          <p class="muted">Assigns hub projects to customers from SO live data where each project code maps to one customer.</p>
        </div>
        <div class="links">
          <a href="/super.php">Super</a>
          <a href="/developer.php">Developer</a>
          <a href="/admin/projects.php">Projects</a>
        </div>
      </div>
      <div class="alert success"><?php echo hub_h($msg); ?></div>
      <?php if (!empty($conflicts)): ?>
        <h2>Review Needed</h2>
        <div class="import-data-table-wrap">
          <table class="table admin-data-table import-data-table">
            <thead>
              <tr>
                <th>Project</th>
                <th>Status</th>
                <th>Detail</th>
                <th>Rows</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($conflicts as $conflict): ?>
                <tr>
                  <td><?php echo hub_h((string) $conflict['project_code']); ?></td>
                  <td><?php echo hub_h((string) $conflict['status']); ?></td>
                  <td><?php echo hub_h((string) $conflict['detail']); ?></td>
                  <td><?php echo number_format((int) $conflict['row_count']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
