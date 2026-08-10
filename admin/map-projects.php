<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_admin();

global $pdo, $DB_OK;

$errors = [];

function hub_map_projects_customer_label(array $customer): string {
  $name = trim((string) ($customer['name'] ?? ''));
  $code = trim((string) ($customer['code'] ?? ''));
  if ($name !== '' && $code !== '') {
    return $name . ' (' . $code . ')';
  }
  return $name !== '' ? $name : ($code !== '' ? $code : 'Customer #' . (int) ($customer['id'] ?? 0));
}

function hub_map_projects_complete_review_action(int $projectId): void {
  global $pdo;

  if (!hub_table_exists('hub_admin_action')) {
    return;
  }

  $stmt = $pdo->prepare(
    'UPDATE hub_admin_action
     SET status = :status, modified = NOW()
     WHERE entity_type = :entity_type
       AND entity_id = :entity_id
       AND action_type = :action_type
       AND status = :open_status'
  );
  $stmt->execute([
    ':status' => 'complete',
    ':entity_type' => 'project',
    ':entity_id' => $projectId,
    ':action_type' => 'review_project_customer',
    ':open_status' => 'open',
  ]);
}

function hub_map_projects_so_evidence(): array {
  global $pdo;

  if (!hub_table_exists('hub_so_live')) {
    return [];
  }

  $stmt = $pdo->query(
    'SELECT
       TRIM(project) AS project_code,
       COUNT(*) AS row_count,
       COUNT(DISTINCT CASE WHEN customer_id IS NOT NULL AND customer_id > 0 THEN customer_id END) AS customer_count,
       MIN(CASE WHEN customer_id IS NOT NULL AND customer_id > 0 THEN customer_id END) AS inferred_customer_id
     FROM hub_so_live
     WHERE project IS NOT NULL
       AND TRIM(project) <> ""
       AND archived = 0
     GROUP BY TRIM(project)'
  );

  $evidence = [];
  foreach ($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
    $code = trim((string) ($row['project_code'] ?? ''));
    if ($code === '') {
      continue;
    }
    $evidence[$code] = [
      'row_count' => (int) ($row['row_count'] ?? 0),
      'customer_count' => (int) ($row['customer_count'] ?? 0),
      'inferred_customer_id' => isset($row['inferred_customer_id']) ? (int) $row['inferred_customer_id'] : 0,
    ];
  }
  return $evidence;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_project_customers') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $errors[] = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_project') || !hub_table_exists('hub_customer')) {
    $errors[] = 'Database tables are not available.';
  } else {
    $projectIds = is_array($_POST['project_id'] ?? null) ? $_POST['project_id'] : [];
    $customerIds = is_array($_POST['customer_id'] ?? null) ? $_POST['customer_id'] : [];
    $saveIdx = array_key_exists('save_idx', $_POST) ? (string) $_POST['save_idx'] : null;
    $saved = 0;

    $stmtCustomer = $pdo->prepare('SELECT id FROM hub_customer WHERE id = :id AND archived = 0 LIMIT 1');
    $stmtProject = $pdo->prepare('SELECT id, code FROM hub_project WHERE id = :id LIMIT 1');
    $stmtUpdate = $pdo->prepare('UPDATE hub_project SET customer_id = :customer_id, modified = NOW() WHERE id = :id LIMIT 1');

    foreach ($projectIds as $idx => $projectIdRaw) {
      if ($saveIdx !== null && (string) $idx !== $saveIdx) {
        continue;
      }

      $projectId = (int) $projectIdRaw;
      $customerId = (int) ($customerIds[$idx] ?? 0);
      if ($projectId <= 0 || $customerId <= 0) {
        continue;
      }

      $stmtCustomer->execute([':id' => $customerId]);
      if (!(int) $stmtCustomer->fetchColumn()) {
        $errors[] = 'Selected customer was not found for project ID ' . $projectId . '.';
        continue;
      }

      $stmtProject->execute([':id' => $projectId]);
      $project = $stmtProject->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$project) {
        $errors[] = 'Project ID ' . $projectId . ' was not found.';
        continue;
      }

      $stmtUpdate->execute([':customer_id' => $customerId, ':id' => $projectId]);
      hub_map_projects_complete_review_action($projectId);
      $saved++;
    }

    if (!$errors) {
      if ($saved > 0) {
        hub_flash('success', 'Saved ' . $saved . ' project customer assignment' . ($saved === 1 ? '' : 's') . '.');
      } else {
        hub_flash('info', 'No project assignments were saved. Select a customer first.');
      }
      hub_redirect('/admin/map-projects.php');
    }
  }
}

$customers = [];
$projects = [];
$messages = hub_flash_messages();
$soEvidence = [];

if ($DB_OK && ($pdo instanceof PDO)) {
  if (hub_table_exists('hub_customer')) {
    $stmtCustomers = $pdo->query('SELECT id, code, name FROM hub_customer WHERE archived = 0 ORDER BY name ASC, code ASC, id ASC');
    $customers = $stmtCustomers ? ($stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  }

  $soEvidence = hub_map_projects_so_evidence();

  if (hub_table_exists('hub_project')) {
    $reviewJoin = hub_table_exists('hub_admin_action')
      ? 'LEFT JOIN hub_admin_action a ON a.entity_type = "project" AND a.entity_id = p.id AND a.action_type = "review_project_customer" AND a.status = "open"'
      : 'LEFT JOIN (SELECT NULL AS id, NULL AS entity_id) a ON 1 = 0';

    $stmtProjects = $pdo->query(
      'SELECT p.id, p.code, p.name, p.customer_id, p.needs_review, p.source,
              c.code AS customer_code, c.name AS customer_name,
              MAX(a.id) AS review_action_id
       FROM hub_project p
       LEFT JOIN hub_customer c ON c.id = p.customer_id
       ' . $reviewJoin . '
       WHERE p.archived = 0
         AND (p.customer_id IS NULL OR p.customer_id = 0 OR a.id IS NOT NULL)
       GROUP BY p.id, p.code, p.name, p.customer_id, p.needs_review, p.source, c.code, c.name
       ORDER BY (p.customer_id IS NULL OR p.customer_id = 0) DESC, p.code ASC, p.id ASC
       LIMIT 1000'
    );
    $projects = $stmtProjects ? ($stmtProjects->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Map Projects</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page map-projects-page">
  <?php echo hub_admin_header('admin'); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card mapping-controls">
        <div class="flex">
          <div>
            <p class="brand">Imports</p>
            <h1>Map Projects</h1>
            <p class="muted">Assign persistent project records to customers where the import could not safely infer the customer.</p>
          </div>
          <div class="links">
            <a href="/admin.php">Back to admin</a>
            <a href="/admin/projects.php">Projects</a>
            <a href="/tools/backfill_project_customers.php">Run backfill</a>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endforeach; ?>
      </div>

      <div class="card mapping-list-card">
        <div class="flex">
          <div>
            <p class="brand">Review</p>
            <h2>Projects needing customer assignment</h2>
            <p class="muted">Only projects with no assigned customer or an open project-customer review action are shown.</p>
          </div>
        </div>

        <?php if (!$projects): ?>
          <div class="alert success">No projects need customer assignment or review.</div>
        <?php else: ?>
          <form method="post" action="/admin/map-projects.php">
            <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
            <input type="hidden" name="action" value="save_project_customers">
            <div class="import-data-table-wrap">
              <table class="table mapping-table admin-data-table import-data-table">
                <thead>
                  <tr>
                    <th>Project</th>
                    <th>Name</th>
                    <th>Current customer</th>
                    <th>SO evidence</th>
                    <th>Assign customer</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($projects as $idx => $project): ?>
                    <?php
                      $projectId = (int) $project['id'];
                      $projectCode = trim((string) $project['code']);
                      $evidence = $soEvidence[$projectCode] ?? null;
                      $currentCustomerLabel = trim((string) (($project['customer_name'] ?? '') ?: ($project['customer_code'] ?? '')));
                      $currentCustomerId = (int) ($project['customer_id'] ?? 0);
                      $suggestedCustomerId = 0;
                      $evidenceText = 'Not in current SO live data';
                      if ($evidence) {
                        $rowCount = (int) ($evidence['row_count'] ?? 0);
                        $customerCount = (int) ($evidence['customer_count'] ?? 0);
                        $inferredCustomerId = (int) ($evidence['inferred_customer_id'] ?? 0);
                        if ($customerCount === 1 && $inferredCustomerId > 0) {
                          $suggestedCustomerId = $inferredCustomerId;
                          $evidenceText = number_format($rowCount) . ' SO rows, one customer ID: ' . $inferredCustomerId;
                        } elseif ($customerCount > 1) {
                          $evidenceText = number_format($rowCount) . ' SO rows, ' . number_format($customerCount) . ' customer IDs';
                        } else {
                          $evidenceText = number_format($rowCount) . ' SO rows, no mapped customer ID';
                        }
                      }
                      $selectedCustomerId = $currentCustomerId > 0 ? $currentCustomerId : $suggestedCustomerId;
                    ?>
                    <tr>
                      <td>
                        <strong><?php echo hub_h($projectCode); ?></strong>
                        <input type="hidden" name="project_id[<?php echo (int) $idx; ?>]" value="<?php echo $projectId; ?>">
                      </td>
                      <td><?php echo hub_h((string) ($project['name'] ?? '')); ?></td>
                      <td><?php echo $currentCustomerLabel !== '' ? hub_h($currentCustomerLabel) : '<span class="muted">Not assigned</span>'; ?></td>
                      <td><?php echo hub_h($evidenceText); ?></td>
                      <td>
                        <select name="customer_id[<?php echo (int) $idx; ?>]">
                          <option value="0">Select customer</option>
                          <?php foreach ($customers as $customer): ?>
                            <?php $customerId = (int) $customer['id']; ?>
                            <option value="<?php echo $customerId; ?>" <?php echo $selectedCustomerId === $customerId ? 'selected' : ''; ?>><?php echo hub_h(hub_map_projects_customer_label($customer)); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td>
                        <div class="mapping-actions">
                          <button type="submit" name="save_idx" value="<?php echo (int) $idx; ?>">Save row</button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="links" style="justify-content:flex-start; margin-top:12px;">
              <button type="submit">Save selected assignments</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
