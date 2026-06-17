<?php
require_once __DIR__ . '/includes/app/auth.php';
hub_require_login();

$user = hub_current_user();
$messages = hub_flash_messages();

global $pdo, $DB_OK;

$showReport = (isset($_GET['report']) && $_GET['report'] === 'sample1');
$customerSelectError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hub_is_admin() && isset($_POST['action']) && $_POST['action'] === 'select_customer') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $customerSelectError = 'Session expired. Please try again.';
  } else {
    $selected = trim((string) ($_POST['customer_id'] ?? ''));
    if ($selected === '') {
      hub_set_customer_override(null);
      hub_flash('success', 'Viewing as your own customer.');
    } else {
      $selectedId = (int) $selected;
      $exists = false;
      if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
        $stmtCheck = $pdo->prepare('SELECT id FROM hub_customer WHERE id = :id LIMIT 1');
        $stmtCheck->execute([':id' => $selectedId]);
        $exists = (bool) $stmtCheck->fetchColumn();
      }
      if ($exists) {
        hub_set_customer_override($selectedId);
        hub_flash('success', 'Viewing as selected customer.');
      } else {
        $customerSelectError = 'Customer not found.';
      }
    }
  }
  hub_redirect('dashboard.php' . ($showReport ? '?report=sample1#sample1' : ''));
}

$effectiveCustomerId = hub_effective_customer_id($user);
$customerCode = null;
$customerName = null;
$companyLabel = null;
if ($effectiveCustomerId && hub_table_exists('hub_customer') && $DB_OK && ($pdo instanceof PDO)) {
  $stmtCust = $pdo->prepare('SELECT code, name FROM hub_customer WHERE id = :id LIMIT 1');
  $stmtCust->execute([':id' => (int) $effectiveCustomerId]);
  if ($row = $stmtCust->fetch(PDO::FETCH_ASSOC)) {
    $customerCode = trim((string) ($row['code'] ?? ''));
    $customerName = (string) ($row['name'] ?? '');
    $companyLabel = $customerName !== '' ? $customerName : $customerCode;
  }
}

function hub_fmt_date(?string $value): string {
  if (!$value) {
    return '';
  }
  try {
    $dt = new DateTime($value);
    return $dt->format('Y-m-d');
  } catch (Throwable $e) {
    return $value;
  }
}

$sampleRows = [];
$statusCounts = [];
$branchOptions = [];
$statusOptions = [];
$projectOptions = [];
if ($customerCode && hub_table_exists('hub_sales_order') && $DB_OK && ($pdo instanceof PDO)) {
  $params = [':code' => $customerCode];
  $where = ['customer_code = :code'];

  $orderNbr = trim((string) ($_GET['order_nbr'] ?? ''));
  if ($orderNbr !== '') {
    $where[] = 'order_nbr LIKE :order_nbr';
    $params[':order_nbr'] = '%' . $orderNbr . '%';
  }

  $branchId = trim((string) ($_GET['branch_id'] ?? ''));
  if ($branchId !== '') {
    $where[] = 'branch_id LIKE :branch_id';
    $params[':branch_id'] = '%' . $branchId . '%';
  }

  $status = trim((string) ($_GET['status'] ?? ''));
  if ($status !== '') {
    $where[] = 'status LIKE :status';
    $params[':status'] = '%' . $status . '%';
  }

  $project = trim((string) ($_GET['project'] ?? ''));
  if ($project !== '') {
    $where[] = 'project LIKE :project';
    $params[':project'] = '%' . $project . '%';
  }

  $owner = trim((string) ($_GET['owner'] ?? ''));
  if ($owner !== '') {
    $where[] = 'owner LIKE :owner';
    $params[':owner'] = '%' . $owner . '%';
  }

  $sort = $_GET['sort'] ?? 'requested_on_desc';
  $sortSql = 'requested_on DESC, order_nbr DESC';
  if ($sort === 'requested_on_asc') {
    $sortSql = 'requested_on ASC, order_nbr ASC';
  } elseif ($sort === 'order_nbr_asc') {
    $sortSql = 'order_nbr ASC';
  } elseif ($sort === 'order_nbr_desc') {
    $sortSql = 'order_nbr DESC';
  } elseif ($sort === 'branch_id_asc') {
    $sortSql = 'branch_id ASC, order_nbr DESC';
  } elseif ($sort === 'branch_id_desc') {
    $sortSql = 'branch_id DESC, order_nbr DESC';
  } elseif ($sort === 'status_asc') {
    $sortSql = 'status ASC, order_nbr DESC';
  } elseif ($sort === 'status_desc') {
    $sortSql = 'status DESC, order_nbr DESC';
  } elseif ($sort === 'project_asc') {
    $sortSql = 'project ASC, order_nbr DESC';
  } elseif ($sort === 'project_desc') {
    $sortSql = 'project DESC, order_nbr DESC';
  } elseif ($sort === 'sched_shipment_asc') {
    $sortSql = 'sched_shipment ASC, order_nbr DESC';
  } elseif ($sort === 'sched_shipment_desc') {
    $sortSql = 'sched_shipment DESC, order_nbr DESC';
  } elseif ($sort === 'owner_asc') {
    $sortSql = 'owner ASC, order_nbr DESC';
  } elseif ($sort === 'owner_desc') {
    $sortSql = 'owner DESC, order_nbr DESC';
  }

  $sql = 'SELECT order_nbr, branch_id, status, project, requested_on, sched_shipment, owner, description
          FROM hub_sales_order
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY ' . $sortSql . '
          LIMIT 500';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $sampleRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $countSql = 'SELECT status, COUNT(*) AS cnt FROM hub_sales_order WHERE ' . implode(' AND ', $where) . ' GROUP BY status';
  $stmtCount = $pdo->prepare($countSql);
  $stmtCount->execute($params);
  $statusCounts = $stmtCount->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Distinct options for selects (unfiltered, per customer).
  $stmtBranch = $pdo->prepare('SELECT DISTINCT branch_id FROM hub_sales_order WHERE customer_code = :code AND branch_id IS NOT NULL AND TRIM(branch_id) <> "" ORDER BY branch_id LIMIT 200');
  $stmtBranch->execute([':code' => $customerCode]);
  $branchOptions = $stmtBranch->fetchAll(PDO::FETCH_COLUMN) ?: [];

  $stmtStatus = $pdo->prepare('SELECT DISTINCT status FROM hub_sales_order WHERE customer_code = :code AND status IS NOT NULL AND TRIM(status) <> "" ORDER BY status LIMIT 200');
  $stmtStatus->execute([':code' => $customerCode]);
  $statusOptions = $stmtStatus->fetchAll(PDO::FETCH_COLUMN) ?: [];

  $stmtProject = $pdo->prepare('SELECT DISTINCT project FROM hub_sales_order WHERE customer_code = :code AND project IS NOT NULL AND TRIM(project) <> "" ORDER BY project LIMIT 200');
  $stmtProject->execute([':code' => $customerCode]);
  $projectOptions = $stmtProject->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function hub_current_filters(): array {
  return [
    'order_nbr' => (string) ($_GET['order_nbr'] ?? ''),
    'branch_id' => (string) ($_GET['branch_id'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
    'project' => (string) ($_GET['project'] ?? ''),
    'owner' => (string) ($_GET['owner'] ?? ''),
    'sort' => (string) ($_GET['sort'] ?? 'requested_on_desc'),
  ];
}

function hub_sort_link(string $column, string $label): string {
  $filters = hub_current_filters();
  $currentSort = $filters['sort'] ?? 'requested_on_desc';
  $ascKey = $column . '_asc';
  $descKey = $column . '_desc';
  $next = ($currentSort === $ascKey) ? $descKey : $ascKey;
  $filters['sort'] = $next;
  $filters['report'] = 'sample1';
  $qs = http_build_query($filters);
  $arrow = '';
  if ($currentSort === $ascKey) {
    $arrow = ' ▲';
  } elseif ($currentSort === $descKey) {
    $arrow = ' ▼';
  } else {
    $arrow = ' ↕';
  }
  return '<a href="dashboard.php?' . hub_h($qs) . '#sample1">' . hub_h($label) . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Dashboard</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand"><?php echo hub_h(HUB_APP_NAME); ?></p>
            <h1>
              Welcome back<?php echo $user['display_name'] ? ', ' . hub_h($user['display_name']) : ''; ?>
              <?php if ($companyLabel): ?>
                <span class="muted">(<?php echo hub_h($companyLabel); ?>)</span>
              <?php endif; ?>.
            </h1>
            <p class="muted">This dashboard will surface your JSON-derived metrics and custom reports.</p>
          </div>
          <div class="links">
            <span class="badge"><?php echo hub_h($user['role'] ?? 'user'); ?></span>
            <?php if (hub_is_admin()): ?>
              <a href="/admin.php">Admin</a>
              <a href="#" id="openCustomerModal">Change Customer</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>

        <div class="dashboard">
          <div class="stat">
            <strong>Customer</strong>
            <p class="muted">ID: <?php echo hub_h((string) ($effectiveCustomerId ?? '')); ?></p>
            <?php if (!empty($customerName)): ?>
              <p class="muted">Name: <?php echo hub_h($customerName); ?></p>
            <?php endif; ?>
            <?php if (!empty($customerCode)): ?>
              <p class="muted">Code: <?php echo hub_h($customerCode); ?></p>
            <?php endif; ?>
            <p class="muted">Email: <a href="mailto:<?php echo hub_h((string) ($user['email'] ?? '')); ?>"><?php echo hub_h((string) ($user['email'] ?? '')); ?></a></p>
          </div>
          <div class="stat">
            <strong>JSON ingestion</strong>
            <p class="muted">Processing stub pending schema spec.</p>
          </div>
          <div class="stat">
            <strong>Reports</strong>
            <p class="muted">Custom reporting surface will live here.</p>
            <div class="links">
              <a href="dashboard.php?report=sample1#sample1">Sample1</a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($showReport && !empty($statusCounts)): ?>
      <div class="card">
        <p class="brand">At a glance</p>
        <div class="stat-pills">
          <?php
            $palette = ['#1f9acb', '#0ec27a', '#ff8c00', '#a855f7', '#ef4444', '#14b8a6', '#f97316', '#6366f1'];
            foreach ($statusCounts as $idx => $st):
              $color = $palette[$idx % count($palette)];
              $label = trim((string) ($st['status'] ?? 'Unknown'));
              $countVal = (int) ($st['cnt'] ?? 0);
          ?>
            <div class="stat-pill" style="background: <?php echo hub_h($color); ?>;">
              <div class="label"><?php echo hub_h($label === '' ? 'Unknown' : $label); ?></div>
              <div class="value"><?php echo hub_h((string) $countVal); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($showReport): ?>
      <div class="card" id="sample1">
        <p class="brand">Reports</p>
        <h1>Sample1 (Sales Orders)</h1>
        <p class="muted">Showing sales orders for customer code <?php echo hub_h($customerCode ?? 'N/A'); ?>.</p>

        <?php if (!$customerCode): ?>
          <div class="alert info">No customer code linked to your user yet.</div>
        <?php else: ?>
          <?php $filters = hub_current_filters(); ?>
          <form id="reportFilters" method="get" action="dashboard.php#sample1">
            <input type="hidden" name="report" value="sample1">
            <input type="hidden" name="sort" value="<?php echo hub_h($filters['sort']); ?>">
            <table class="table">
            <thead>
              <tr>
                <th><?php echo hub_sort_link('order_nbr', 'Order'); ?></th>
                <th><?php echo hub_sort_link('branch_id', 'Branch'); ?></th>
                <th><?php echo hub_sort_link('status', 'Status'); ?></th>
                <th><?php echo hub_sort_link('project', 'Project'); ?></th>
                <th><?php echo hub_sort_link('requested_on', 'Requested'); ?></th>
                <th><?php echo hub_sort_link('sched_shipment', 'Sched Ship'); ?></th>
                <th><?php echo hub_sort_link('owner', 'Owner'); ?></th>
              </tr>
              <tr>
                <th><input name="order_nbr" type="text" value="<?php echo hub_h($filters['order_nbr']); ?>" placeholder="Filter order"></th>
                <th>
                  <select name="branch_id">
                    <option value="">All branches</option>
                    <?php foreach ($branchOptions as $opt): ?>
                      <?php $sel = ($filters['branch_id'] === (string) $opt) ? 'selected' : ''; ?>
                      <option value="<?php echo hub_h((string) $opt); ?>" <?php echo $sel; ?>><?php echo hub_h((string) $opt); ?></option>
                    <?php endforeach; ?>
                  </select>
                </th>
                <th>
                  <select name="status">
                    <option value="">All status</option>
                    <?php foreach ($statusOptions as $opt): ?>
                      <?php $sel = ($filters['status'] === (string) $opt) ? 'selected' : ''; ?>
                      <option value="<?php echo hub_h((string) $opt); ?>" <?php echo $sel; ?>><?php echo hub_h((string) $opt); ?></option>
                    <?php endforeach; ?>
                  </select>
                </th>
                <th>
                  <select name="project">
                    <option value="">All projects</option>
                    <?php foreach ($projectOptions as $opt): ?>
                      <?php $sel = ($filters['project'] === (string) $opt) ? 'selected' : ''; ?>
                      <option value="<?php echo hub_h((string) $opt); ?>" <?php echo $sel; ?>><?php echo hub_h((string) $opt); ?></option>
                    <?php endforeach; ?>
                  </select>
                </th>
                <th><input name="requested_on" type="text" disabled placeholder="via sort"></th>
                <th><input name="sched_shipment" type="text" disabled placeholder="via sort"></th>
                <th><input name="owner" type="text" value="<?php echo hub_h($filters['owner']); ?>" placeholder="Filter owner"></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($sampleRows)): ?>
                <tr>
                  <td colspan="7" class="muted">No sales orders found for this filter.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($sampleRows as $row): ?>
                  <tr>
                    <td><?php echo hub_h((string) $row['order_nbr']); ?></td>
                    <td><?php echo hub_h((string) $row['branch_id']); ?></td>
                    <td><?php echo hub_h((string) $row['status']); ?></td>
                    <td><?php echo hub_h((string) $row['project']); ?></td>
                    <td><?php echo hub_h(hub_fmt_date($row['requested_on'])); ?></td>
                    <td><?php echo hub_h(hub_fmt_date($row['sched_shipment'])); ?></td>
                    <td><?php echo hub_h((string) $row['owner']); ?></td>
                  </tr>
                  <tr>
                    <td></td>
                    <td colspan="6" class="muted"><?php echo hub_h((string) $row['description']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            </table>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (hub_is_admin()): ?>
    <div class="modal" id="customerModal">
      <div class="modal-content">
        <div class="modal-header">
          <h2>Select customer</h2>
          <button class="close-btn" id="closeCustomerModal" aria-label="Close">&times;</button>
        </div>
        <?php if ($customerSelectError): ?>
          <div class="alert error"><?php echo hub_h($customerSelectError); ?></div>
        <?php endif; ?>
        <form method="post" action="dashboard.php">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <input type="hidden" name="action" value="select_customer">
          <div style="display:grid; gap:10px;">
            <label for="customer_id_modal">View as customer:</label>
            <select id="customer_id_modal" name="customer_id" style="padding: 10px; border-radius: 8px;">
              <option value="">-- Use my own --</option>
              <?php
              if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
                $stmtList = $pdo->query('SELECT id, name, code FROM hub_customer WHERE archived = 0 ORDER BY name ASC LIMIT 200');
                $options = $stmtList ? $stmtList->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($options as $opt) {
                  $idVal = (int) $opt['id'];
                  $selected = ($effectiveCustomerId === $idVal) ? 'selected' : '';
                  $label = trim((string) $opt['name']) !== '' ? $opt['name'] : $opt['code'];
                  echo '<option value="' . hub_h((string) $idVal) . "\" {$selected}>" . hub_h($label . ' (' . $opt['code'] . ')') . '</option>';
                }
              }
              ?>
            </select>
            <div class="links" style="justify-content: flex-end;">
              <button type="submit">Apply</button>
            </div>
          </div>
        </form>
      </div>
    </div>
    <script>
      (function() {
        const modal = document.getElementById('customerModal');
        const openBtn = document.getElementById('openCustomerModal');
        const closeBtn = document.getElementById('closeCustomerModal');

        function openModal(e) {
          if (e) e.preventDefault();
          if (modal) modal.classList.add('open');
        }

        function closeModal(e) {
          if (e) e.preventDefault();
          if (modal) modal.classList.remove('open');
        }

        if (openBtn) openBtn.addEventListener('click', openModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (modal) {
          modal.addEventListener('click', function(e) {
            if (e.target === modal) {
              closeModal(e);
            }
          });
        }
      })();
    </script>
  <?php endif; ?>
  <script>
    (function() {
      const form = document.getElementById('reportFilters');
      if (!form) return;
      const inputs = form.querySelectorAll('input[name], select[name]');
      let timer = null;
      function submitNow() {
        form.submit();
      }
      function debounceSubmit() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(submitNow, 300);
      }
      inputs.forEach((el) => {
        if (el.tagName.toLowerCase() === 'select') {
          el.addEventListener('change', submitNow);
        } else {
          el.addEventListener('input', debounceSubmit);
          el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              submitNow();
            }
          });
        }
      });
    })();
  </script>
</body>
</html>
