<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_admin();

global $pdo, $DB_OK;

$error = null;

function hub_admin_projects_customer_label(array $row): string {
  return trim((string) (($row['customer_name'] ?? '') ?: ($row['customer_code'] ?? '')));
}

function hub_admin_projects_row_value(array $row, string $column): string {
  if ($column === 'id') {
    return (string) ($row['id'] ?? '');
  }
  if ($column === 'customer_id') {
    return (string) (int) ($row['customer_id'] ?? 0);
  }
  if ($column === 'customer') {
    return hub_admin_projects_customer_label($row);
  }
  return trim((string) ($row[$column] ?? ''));
}

function hub_admin_projects_row_matches_filters(array $row, array $filters, ?string $exceptColumn = null): bool {
  foreach ($filters as $column => $filter) {
    $column = (string) $column;
    if ($exceptColumn !== null && $column === $exceptColumn) {
      continue;
    }
    $filter = trim((string) $filter);
    if ($filter === '') {
      continue;
    }
    $value = hub_admin_projects_row_value($row, $column);
    if (in_array($column, ['id', 'code', 'name'], true)) {
      if (mb_strpos(mb_strtolower($value), mb_strtolower($filter)) === false) {
        return false;
      }
      continue;
    }
    if ($column === 'customer_id') {
      if ($value !== $filter) {
        return false;
      }
      continue;
    }
  }
  return true;
}

function hub_admin_projects_has_filters(array $filters): bool {
  foreach ($filters as $filter) {
    if (trim((string) $filter) !== '') {
      return true;
    }
  }
  return false;
}

function hub_admin_projects_url(array $params): string {
  $current = [
    'page' => $_GET['page'] ?? 1,
    'per_page' => $_GET['per_page'] ?? 50,
    'col' => $_GET['col'] ?? [],
    'sort' => $_GET['sort'] ?? 'id_asc',
  ];
  return '/admin/projects.php?' . http_build_query(array_merge($current, $params));
}

function hub_admin_projects_sort_link(string $column, string $label, string $currentSort): string {
  $ascKey = $column . '_asc';
  $descKey = $column . '_desc';
  $next = ($currentSort === $ascKey) ? $descKey : $ascKey;
  $arrow = ' ↕';
  if ($currentSort === $ascKey) {
    $arrow = ' ▲';
  } elseif ($currentSort === $descKey) {
    $arrow = ' ▼';
  }
  return '<a href="' . hub_h(hub_admin_projects_url(['sort' => $next, 'page' => 1])) . '">' . hub_h($label) . $arrow . '</a>';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add_project', 'update_project'], true)) {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    $action = (string) $_POST['action'];
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $code = trim((string) ($_POST['code'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $customerId = trim((string) ($_POST['customer_id'] ?? '')) === '' ? null : (int) $_POST['customer_id'];

    if ($code === '') {
      $error = 'Project code is required.';
    } elseif ($action === 'update_project' && $projectId <= 0) {
      $error = 'Project record not found.';
    } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_project')) {
      $error = 'Database not available.';
    } else {
      try {
        if ($action === 'add_project') {
          $stmt = $pdo->prepare(
            'INSERT INTO hub_project
              (customer_id, code, name, notes, source, needs_review, show_on_web, archived, created, modified)
             VALUES
              (:customer_id, :code, :name, NULL, :source, :needs_review, 1, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
              customer_id = VALUES(customer_id),
              name = VALUES(name),
              needs_review = VALUES(needs_review),
              modified = NOW()'
          );
          $stmt->execute([
            ':customer_id' => $customerId,
            ':code' => $code,
            ':name' => $name !== '' ? $name : null,
            ':source' => 'manual',
            ':needs_review' => $name === '' ? 1 : 0,
          ]);
          hub_flash('success', 'Project saved.');
        } else {
          $stmt = $pdo->prepare(
            'UPDATE hub_project
             SET customer_id = :customer_id,
                 code = :code,
                 name = :name,
                 needs_review = :needs_review,
                 modified = NOW()
             WHERE id = :id
             LIMIT 1'
          );
          $stmt->execute([
            ':customer_id' => $customerId,
            ':code' => $code,
            ':name' => $name !== '' ? $name : null,
            ':needs_review' => $name === '' ? 1 : 0,
            ':id' => $projectId,
          ]);
          hub_flash('success', 'Project updated.');
        }
        hub_redirect('/admin/projects.php');
      } catch (PDOException $e) {
        $error = 'Unable to save project.';
      }
    }
  }
}
$customers = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
  $stmtCustomers = $pdo->query('SELECT id, code, name FROM hub_customer WHERE archived = 0 ORDER BY name ASC, code ASC');
  $customers = $stmtCustomers ? ($stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

$columnFilters = is_array($_GET['col'] ?? null) ? $_GET['col'] : [];
$columnFilters = array_map(static function ($value): string {
  return trim((string) $value);
}, $columnFilters);
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min(200, $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = trim((string) ($_GET['sort'] ?? 'id_asc'));
$allowedSortColumns = array_fill_keys(['id', 'code', 'name', 'customer'], true);
$totalRows = 0;
$totalPages = 1;
$allProjects = [];
$projects = [];
$filterOptions = ['customer_id' => []];

if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_project')) {
  $stmtProjects = $pdo->query(
    'SELECT p.*, c.code AS customer_code, c.name AS customer_name
     FROM hub_project p
     LEFT JOIN hub_customer c ON c.id = p.customer_id
     ORDER BY p.needs_review DESC, p.code ASC, p.id ASC
     LIMIT 5000'
  );
  $allProjects = $stmtProjects ? ($stmtProjects->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

  foreach ($allProjects as $row) {
    if (!hub_admin_projects_row_matches_filters($row, $columnFilters, 'customer_id')) {
      continue;
    }
    $customerId = (int) ($row['customer_id'] ?? 0);
    if ($customerId > 0) {
      $label = hub_admin_projects_customer_label($row);
      $filterOptions['customer_id'][$customerId] = $label !== '' ? $label : ('Customer ' . $customerId);
    }
  }
  asort($filterOptions['customer_id'], SORT_NATURAL | SORT_FLAG_CASE);

  $filteredProjects = [];
  foreach ($allProjects as $row) {
    if (hub_admin_projects_row_matches_filters($row, $columnFilters)) {
      $filteredProjects[] = $row;
    }
  }

  $sortParts = explode('_', $sort);
  $sortDirection = array_pop($sortParts);
  $sortColumn = implode('_', $sortParts);
  if (!isset($allowedSortColumns[$sortColumn]) || !in_array($sortDirection, ['asc', 'desc'], true)) {
    $sortColumn = 'id';
    $sortDirection = 'asc';
    $sort = 'id_asc';
  }
  usort($filteredProjects, static function (array $left, array $right) use ($sortColumn, $sortDirection): int {
    $leftValue = hub_admin_projects_row_value($left, $sortColumn);
    $rightValue = hub_admin_projects_row_value($right, $sortColumn);
    if (is_numeric($leftValue) && is_numeric($rightValue)) {
      $result = ((float) $leftValue) <=> ((float) $rightValue);
    } else {
      $result = strnatcasecmp((string) $leftValue, (string) $rightValue);
    }
    return $sortDirection === 'desc' ? -$result : $result;
  });

  $totalRows = count($filteredProjects);
  $totalPages = max(1, (int) ceil($totalRows / $perPage));
  $page = min($page, $totalPages);
  $offset = ($page - 1) * $perPage;
  $projects = array_slice($filteredProjects, $offset, $perPage);
}

$messages = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Admin Projects</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page projects-page">
  <?php echo hub_admin_header('admin'); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Admin</p>
            <h1>Projects</h1>
            <p class="muted">Review projects discovered from imports and add project details.</p>
          </div>
          <div class="links">
            <a href="/admin.php">Back to Admin</a>
            <a href="/admin/import-data.php">View Data</a>
            <a href="/dashboard.php">Dashboard</a>
            <button type="button" data-open-modal="projectAddModal">Add New Project</button>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endif; ?>
      </div>

      <div class="card">
        <p class="brand">List</p>
        <h2>Existing Projects</h2>
        <form method="get" action="/admin/projects.php" class="import-data-controls admin-table-controls" id="projects-filter-form">
          <div>
            <label for="per_page">Rows Per Page</label>
            <select id="per_page" name="per_page" onchange="this.form.submit()">
              <?php foreach ([10, 25, 50, 100, 200] as $option): ?>
                <option value="<?php echo $option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <input type="hidden" name="page" value="1">
          <input type="hidden" name="sort" value="<?php echo hub_h($sort); ?>">
          <?php if (hub_admin_projects_has_filters($columnFilters)): ?>
            <div class="links import-data-reset">
              <a href="/admin/projects.php?per_page=<?php echo (int) $perPage; ?>&sort=<?php echo urlencode($sort); ?>">Clear Filters</a>
            </div>
          <?php endif; ?>
        </form>

        <?php if (empty($allProjects)): ?>
          <div class="alert info">No Projects Found.</div>
        <?php else: ?>
          <div class="import-data-table-wrap">
            <table class="table projects-table import-data-table">
              <thead>
                <tr>
                  <th><?php echo hub_admin_projects_sort_link('id', 'ID', $sort); ?></th>
                  <th><?php echo hub_admin_projects_sort_link('code', 'Code', $sort); ?></th>
                  <th><?php echo hub_admin_projects_sort_link('name', 'Name', $sort); ?></th>
                  <th><?php echo hub_admin_projects_sort_link('customer', 'Customer', $sort); ?></th>
                  <th class="table-actions-heading">Action</th>
                </tr>
                <tr class="import-data-filter-row">
                  <th><input name="col[id]" form="projects-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['id'] ?? '')); ?>" placeholder="Search"></th>
                  <th><input name="col[code]" form="projects-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['code'] ?? '')); ?>" placeholder="Search"></th>
                  <th><input name="col[name]" form="projects-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['name'] ?? '')); ?>" placeholder="Search"></th>
                  <th>
                    <select name="col[customer_id]" form="projects-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach ($filterOptions['customer_id'] as $customerId => $label): ?>
                        <option value="<?php echo (int) $customerId; ?>" <?php echo (($columnFilters['customer_id'] ?? '') === (string) $customerId) ? 'selected' : ''; ?>><?php echo hub_h($label); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th class="table-actions-heading"><i class="fa-solid fa-pencil" aria-hidden="true"></i></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($projects)): ?>
                  <tr><td colspan="5" class="muted">No Projects Found.</td></tr>
                <?php else: ?>
                  <?php foreach ($projects as $project): ?>
                    <tr>
                      <td><?php echo (int) $project['id']; ?></td>
                      <td><?php echo hub_h((string) $project['code']); ?></td>
                      <td><?php echo hub_h((string) ($project['name'] ?? '')); ?></td>
                      <td><?php echo hub_h(hub_admin_projects_customer_label($project)); ?></td>
                      <td class="table-actions"><button type="button" class="icon-action" data-open-modal="projectEditModal<?php echo (int) $project['id']; ?>" title="Edit <?php echo hub_h((string) ($project['name'] ?: $project['code'])); ?>" aria-label="Edit <?php echo hub_h((string) ($project['name'] ?: $project['code'])); ?>"><i class="fa-solid fa-pencil" aria-hidden="true"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php foreach ($projects as $project): ?>
            <?php $modalId = 'projectEditModal' . (int) $project['id']; ?>
            <div class="modal admin-entity-modal" id="<?php echo hub_h($modalId); ?>" aria-hidden="true">
              <div class="modal-content">
                <div class="modal-header">
                  <h2>Edit Project</h2>
                  <button type="button" class="close-btn but2" data-close-modal="<?php echo hub_h($modalId); ?>" aria-label="Close">&times;</button>
                </div>
                <form method="post" action="/admin/projects.php">
                  <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                  <input type="hidden" name="action" value="update_project">
                  <input type="hidden" name="project_id" value="<?php echo (int) $project['id']; ?>">
                  <?php echo hub_admin_project_form_fields($project, $customers, 'edit_project_' . (int) $project['id']); ?>
                  <div class="links admin-page-actions"><button type="submit">Save Project</button></div>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if ($totalRows > 0): ?>
            <nav class="import-data-pagination" aria-label="Project pages">
              <div class="links">
                <?php if ($page > 1): ?>
                  <a href="<?php echo hub_h(hub_admin_projects_url(['page' => 1])); ?>">First</a>
                  <a href="<?php echo hub_h(hub_admin_projects_url(['page' => $page - 1])); ?>">Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></span>
                <?php if ($page < $totalPages): ?>
                  <a href="<?php echo hub_h(hub_admin_projects_url(['page' => $page + 1])); ?>">Next</a>
                  <a href="<?php echo hub_h(hub_admin_projects_url(['page' => $totalPages])); ?>">Last</a>
                <?php endif; ?>
              </div>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="modal admin-entity-modal" id="projectAddModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Add New Project</h2>
        <button type="button" class="close-btn but2" data-close-modal="projectAddModal" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="/admin/projects.php">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="add_project">
        <?php echo hub_admin_project_form_fields([], $customers, 'project'); ?>
        <div class="links admin-page-actions"><button type="submit">Save Project</button></div>
      </form>
    </div>
  </div>
  <script>
    (function () {
      document.addEventListener('click', function (event) {
        var open = event.target.closest('[data-open-modal]');
        if (open) {
          var modal = document.getElementById(open.getAttribute('data-open-modal'));
          if (modal) {
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
          }
          return;
        }
        var close = event.target.closest('[data-close-modal]');
        if (close) {
          var closeModal = document.getElementById(close.getAttribute('data-close-modal'));
          if (closeModal) {
            closeModal.classList.remove('open');
            closeModal.setAttribute('aria-hidden', 'true');
          }
          return;
        }
        if (event.target.classList && event.target.classList.contains('modal')) {
          event.target.classList.remove('open');
          event.target.setAttribute('aria-hidden', 'true');
        }
      });
      document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.modal.open').forEach(function (modal) {
          modal.classList.remove('open');
          modal.setAttribute('aria-hidden', 'true');
        });
      });
    })();
  </script>
  <script src="/js/hub.js?v=20260713-table-filters"></script>
</body>
</html><?php
function hub_admin_project_form_fields(array $project, array $customers, string $idPrefix): string {
  ob_start();
  $selectedCustomerId = (int) ($project['customer_id'] ?? 0);
  ?>
  <div>
    <label for="<?php echo hub_h($idPrefix); ?>_code">Project Code *</label>
    <input id="<?php echo hub_h($idPrefix); ?>_code" name="code" type="text" required value="<?php echo hub_h((string) ($project['code'] ?? '')); ?>" placeholder="PM00000090">
  </div>
  <div>
    <label for="<?php echo hub_h($idPrefix); ?>_name">Project Name</label>
    <input id="<?php echo hub_h($idPrefix); ?>_name" name="name" type="text" value="<?php echo hub_h((string) ($project['name'] ?? '')); ?>">
  </div>
  <div>
    <label for="<?php echo hub_h($idPrefix); ?>_customer_id">Customer</label>
    <select id="<?php echo hub_h($idPrefix); ?>_customer_id" name="customer_id">
      <option value="">-- Not Assigned --</option>
      <?php foreach ($customers as $customer): ?>
        <?php
          $customerId = (int) ($customer['id'] ?? 0);
          $label = trim((string) ($customer['name'] ?? ''));
          $code = trim((string) ($customer['code'] ?? ''));
          $label = $label !== '' ? $label . ' (' . $code . ')' : $code;
        ?>
        <option value="<?php echo $customerId; ?>" <?php echo $selectedCustomerId === $customerId ? 'selected' : ''; ?>><?php echo hub_h($label); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php
  return (string) ob_get_clean();
}