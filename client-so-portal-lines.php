<?php
require_once __DIR__ . '/includes/app/auth.php';
hub_require_login();

global $pdo, $DB_OK;

$user = hub_current_user();
$portalDisplayName = trim((string) ($user['display_name'] ?? ''));
if ($portalDisplayName === '') {
  $portalDisplayName = trim((string) ($user['email'] ?? ''));
  if (strpos($portalDisplayName, '@') !== false) {
    $portalDisplayName = strstr($portalDisplayName, '@', true) ?: $portalDisplayName;
  }
}

$effectiveCustomerId = hub_effective_customer_id($user);
$customerLabel = 'No customer selected';
if ($effectiveCustomerId && $DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
  $stmtCustomer = $pdo->prepare('SELECT code, name FROM hub_customer WHERE id = :id LIMIT 1');
  $stmtCustomer->execute([':id' => (int) $effectiveCustomerId]);
  $customer = $stmtCustomer->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($customer) {
    $code = trim((string) ($customer['code'] ?? ''));
    $name = trim((string) ($customer['name'] ?? ''));
    $customerLabel = $name !== '' ? $name : $code;
  }
}

$selectedStatus = trim((string) ($_GET['status'] ?? ''));
$rows = [];
$statusCounts = [];

function hub_client_table_column_exists(string $table, string $column): bool {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO)) {
    return false;
  }

  try {
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE :column');
    $stmt->execute([':column' => $column]);
    return (bool) $stmt->fetchColumn();
  } catch (PDOException $e) {
    return false;
  }
}

if (
  $effectiveCustomerId
  && $DB_OK
  && ($pdo instanceof PDO)
  && hub_table_exists('hub_so_live')
  && hub_client_table_column_exists('hub_so_live', 'customer_id')
  && hub_client_table_column_exists('hub_so_live', 'source_customer_value')
) {
  $sourceAccessParams = [];
  $sourceAccessSql = hub_client_table_column_exists('hub_so_live', 'source_id')
    ? hub_user_source_access_sql('source_id', $user, $sourceAccessParams, 'so_lines_source')
    : '';
  $projectAccessSql = hub_client_table_column_exists('hub_so_live', 'project_id')
    ? hub_user_project_code_access_sql('project_id', $user, $sourceAccessParams, 'so_lines_project')
    : '';

  $stmtCounts = $pdo->prepare(
    'SELECT COALESCE(NULLIF(TRIM(status), ""), "Unknown") AS status, COUNT(*) AS cnt
     FROM hub_so_live
     WHERE customer_id = :customer_id' . $sourceAccessSql . $projectAccessSql . '
     GROUP BY COALESCE(NULLIF(TRIM(status), ""), "Unknown")
     ORDER BY status ASC'
  );
  $stmtCounts->execute(array_merge([':customer_id' => (int) $effectiveCustomerId], $sourceAccessParams));
  foreach ($stmtCounts->fetchAll(PDO::FETCH_ASSOC) ?: [] as $countRow) {
    $statusCounts[(string) $countRow['status']] = (int) $countRow['cnt'];
  }

  $where = 'customer_id = :customer_id' . $sourceAccessSql . $projectAccessSql;
  $params = array_merge([':customer_id' => (int) $effectiveCustomerId], $sourceAccessParams);
  if ($selectedStatus !== '') {
    $where .= ' AND COALESCE(NULLIF(TRIM(status), ""), "Unknown") = :status';
    $params[':status'] = $selectedStatus;
  }

  $stmtRows = $pdo->prepare(
    'SELECT COALESCE(NULLIF(TRIM(status), ""), "Unknown") AS status,
            order_nbr, shipment_nbr, project, description, line_description,
            quantity, lot_serial_nbr, tracking_number, requested_on, shipment_date,
            source_customer_value AS client_filter
     FROM hub_so_live
     WHERE ' . $where . '
     ORDER BY status ASC, order_nbr ASC, line_nbr ASC
     LIMIT 5000'
  );
  $stmtRows->execute($params);
  $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

ksort($statusCounts, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | <?php echo hub_h($customerLabel); ?> SO Portal Lines</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="portal-page">
  <header class="portal-header">
    <a class="portal-logo" href="/dashboard.php" aria-label="RxSource Hub home">
      <img class="portal-logo-image" src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="RxSource Hub">
    </a>
    <div class="portal-welcome">
      Welcome <?php echo hub_h($portalDisplayName ?: 'back'); ?>
    </div>
    <div class="portal-header-actions">
      <?php if (hub_role_at_least('manager')): ?>
        <a class="portal-admin-link" href="/manager.php" title="Manager"><i class="<?php echo hub_h(hub_nav_icon_class('manager')); ?>" aria-hidden="true"></i><span>Manager</span></a>
      <?php endif; ?>
      <?php if (hub_role_at_least('admin')): ?>
        <a class="portal-admin-link" href="/admin.php" title="Admin"><i class="<?php echo hub_h(hub_nav_icon_class('admin')); ?>" aria-hidden="true"></i><span>Admin</span></a>
      <?php endif; ?>
      <?php if (hub_role_at_least('super_admin')): ?>
        <a class="portal-admin-link" href="/super.php" title="Super"><i class="<?php echo hub_h(hub_nav_icon_class('super')); ?>" aria-hidden="true"></i><span>Super</span></a>
      <?php endif; ?>
      <?php if (hub_is_developer()): ?>
        <a class="portal-admin-link" href="/developer.php" title="Developer"><i class="<?php echo hub_h(hub_nav_icon_class('dev')); ?>" aria-hidden="true"></i><span>Dev</span></a>
      <?php endif; ?>
      <a class="portal-admin-link" href="/faq.php?page=so-lines" title="Help"><i class="<?php echo hub_h(hub_nav_icon_class('help')); ?>" aria-hidden="true"></i><span>Help</span></a>
      <a class="portal-admin-link" href="/logout.php" title="Logout"><i class="<?php echo hub_h(hub_nav_icon_class('logout')); ?>" aria-hidden="true"></i><span>Logout</span></a>
      <a class="portal-website-link" href="/dashboard.php" title="Back to Dashboard">Back to Dashboard</a>
    </div>
  </header>

  <main class="stack portal-section portal-content">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand">Proof of concept</p>
          <h1><?php echo hub_h($customerLabel); ?> SO Portal Lines</h1>
          <p class="muted">Live SO Portal Lines filtered by internal customer ID.</p>
        </div>
        <div class="links">
          <a href="/client-so-portal-lines.php">All statuses</a>
          <a href="/admin/import-data.php">Import data</a>
        </div>
      </div>

      <div class="import-data-summary">
        <span><strong><?php echo number_format(count($rows)); ?></strong> rows shown</span>
        <?php if ($selectedStatus !== ''): ?>
          <span>Status: <strong><?php echo hub_h($selectedStatus); ?></strong></span>
        <?php endif; ?>
        <span>Customer: <strong><?php echo hub_h($customerLabel); ?></strong></span>
      </div>

      <?php if (!empty($statusCounts)): ?>
        <div class="links" style="margin-bottom:16px;">
          <?php foreach ($statusCounts as $status => $count): ?>
            <a href="/client-so-portal-lines.php?status=<?php echo urlencode((string) $status); ?>">
              <?php echo hub_h((string) $status); ?> (<?php echo number_format((int) $count); ?>)
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="import-data-table-wrap">
        <table class="table table-dark table-striped table-hover table-sm align-middle import-data-table">
          <thead>
            <tr>
              <th>Status</th>
              <th>Order</th>
              <th>Shipment</th>
              <th>Project</th>
              <th>Description</th>
              <th>Line Description</th>
              <th>Quantity</th>
              <th>Lot/Serial</th>
              <th>Tracking</th>
              <th>Requested</th>
              <th>Shipment Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="11">
                  <div class="alert info" style="margin:0;">No live SO Portal Lines match the current customer/status filter.</div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?php echo hub_h((string) ($row['status'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['order_nbr'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['shipment_nbr'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['project'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['description'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['line_description'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['quantity'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['lot_serial_nbr'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['tracking_number'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['requested_on'] ?? '')); ?></td>
                  <td><?php echo hub_h((string) ($row['shipment_date'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</body>
</html>
