<?php
require_once __DIR__ . '/includes/app/auth.php';
require_once __DIR__ . '/includes/app/report_columns.php';
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

$allRows = [];
$rows = [];
$filteredRows = [];
$reportColumns = hub_report_columns('inventory');
$exportColumns = hub_report_output_columns('inventory', 'is_export');
$dataColumns = hub_report_merge_columns($reportColumns, $exportColumns);
$reportColumnMap = hub_report_column_by_key($reportColumns);
$columns = hub_report_column_keys(hub_report_table_columns($reportColumns));
$reportDetailColumns = hub_report_detail_columns($reportColumns);
$reportLinkColumn = $columns[0] ?? '';
$selectFilterFields = hub_report_select_filter_fields($reportColumns);
$sort = trim((string) ($_GET['sort'] ?? 'InventoryID_asc'));
$allowedSortColumns = array_fill_keys($columns, true);
$columnFilters = is_array($_GET['col'] ?? null) ? $_GET['col'] : [];
$columnFilters = array_map(static function ($value): string {
  return trim((string) $value);
}, $columnFilters);
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min(200, $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalRows = 0;
$totalPages = 1;
$filterOptions = array_fill_keys($selectFilterFields, []);
$atAGlanceSections = [];
$atAGlanceLayout = 'single';

function hub_client_inventory_json_value(array $row, string $wantedKey): string {
  foreach ($row as $key => $value) {
    if (mb_strtolower((string) $key) === mb_strtolower($wantedKey)) {
      return trim((string) $value);
    }
  }
  return '';
}

function hub_client_inventory_format_us_date(string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  try {
    return (new DateTime($value))->format('m/d/Y');
  } catch (Throwable $e) {
    return $value;
  }
}

function hub_client_inventory_column_exists(string $table, string $column): bool {
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

function hub_client_inventory_row_matches_filters(array $row, array $filters, ?string $exceptColumn = null): bool {
  foreach ($filters as $column => $filter) {
    $column = (string) $column;
    if ($exceptColumn !== null && $column === $exceptColumn) {
      continue;
    }
    $filter = trim((string) $filter);
    if ($filter === '') {
      continue;
    }
    $value = (string) ($row[$column] ?? '');
    if (in_array($column, ['InventoryID', 'Warehouse', 'LocationID'], true)) {
      if (trim($value) !== $filter) {
        return false;
      }
      continue;
    }
    if (mb_strpos(mb_strtolower($value), mb_strtolower($filter)) === false) {
      return false;
    }
  }
  return true;
}

function hub_client_inventory_has_filters(array $filters): bool {
  foreach ($filters as $filter) {
    if (trim((string) $filter) !== '') {
      return true;
    }
  }
  return false;
}

function hub_client_inventory_url(array $params): string {
  $current = [
    'page' => $_GET['page'] ?? 1,
    'per_page' => $_GET['per_page'] ?? 50,
    'col' => $_GET['col'] ?? [],
    'sort' => $_GET['sort'] ?? 'id_asc',
  ];
  return '/client-portal-inventory.php?' . http_build_query(array_merge($current, $params));
}

function hub_client_inventory_sort_link(string $column, string $currentSort): string {
  $ascKey = $column . '_asc';
  $descKey = $column . '_desc';
  $next = ($currentSort === $ascKey) ? $descKey : $ascKey;
  $arrow = ' ↕';
  if ($currentSort === $ascKey) {
    $arrow = ' ▲';
  } elseif ($currentSort === $descKey) {
    $arrow = ' ▼';
  }

  return '<a href="' . hub_h(hub_client_inventory_url(['sort' => $next, 'page' => 1])) . '">' . hub_h($column) . $arrow . '</a>';
}

if (
  $effectiveCustomerId
  && $DB_OK
  && ($pdo instanceof PDO)
  && hub_table_exists('hub_inventory_live')
  && hub_client_inventory_column_exists('hub_inventory_live', 'customer_id')
  && hub_client_inventory_column_exists('hub_inventory_live', 'json_data')
) {
  $queryParams = [':customer_id' => (int) $effectiveCustomerId];
  $sourceAccessSql = hub_client_inventory_column_exists('hub_inventory_live', 'source_id')
    ? hub_user_source_access_sql('source_id', $user, $queryParams, 'inventory_source')
    : '';

  $stmtRows = $pdo->prepare(
    'SELECT id, json_data
     FROM hub_inventory_live
     WHERE customer_id = :customer_id
       AND archived = 0
       AND show_on_web = 1
       AND published = 1' . $sourceAccessSql . '
     ORDER BY id ASC
     LIMIT 5000'
  );
  $stmtRows->execute($queryParams);

  foreach ($stmtRows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $json = json_decode((string) ($row['json_data'] ?? ''), true);
    if (!is_array($json)) {
      continue;
    }
    $reportRow = [
      'id' => (int) $row['id'],
    ];
    foreach ($dataColumns as $column) {
      $columnKey = (string) ($column['column_key'] ?? '');
      $dataField = (string) ($column['data_field'] ?? $columnKey);
      $sourceField = (string) ($column['source_field'] ?? $dataField);
      if ($columnKey !== '') {
        $reportRow[$columnKey] = hub_report_json_value($json, $dataField) ?: hub_report_json_value($json, $sourceField);
      }
    }
    $rows[] = $reportRow;
  }
  $allRows = $rows;
  foreach ($selectFilterFields as $field) {
    foreach ($allRows as $row) {
      if (!hub_report_row_matches_filters($row, $columnFilters, $reportColumnMap, $field)) {
        continue;
      }
      $value = trim((string) ($row[$field] ?? ''));
      if ($value !== '') {
        $filterOptions[$field][$value] = true;
      }
    }
    ksort($filterOptions[$field], SORT_NATURAL | SORT_FLAG_CASE);
  }

  $filteredRows = [];
  foreach ($allRows as $row) {
    if (hub_report_row_matches_filters($row, $columnFilters, $reportColumnMap)) {
      $filteredRows[] = $row;
    }
  }
  $atAGlanceSections = hub_report_at_a_glance_sections('inventory', $reportColumns, $allRows, $filteredRows, $columnFilters, '/client-portal-inventory.php');
  $atAGlanceLayout = hub_report_at_a_glance_layout($atAGlanceSections);

  $sortParts = explode('_', $sort);
  $sortDirection = array_pop($sortParts);
  $sortColumn = implode('_', $sortParts);
  if (!isset($allowedSortColumns[$sortColumn]) || !in_array($sortDirection, ['asc', 'desc'], true)) {
    $sortColumn = $columns[0] ?? 'InventoryID';
    $sortDirection = 'asc';
    $sort = $sortColumn . '_asc';
  }
  usort($filteredRows, static function (array $left, array $right) use ($sortColumn, $sortDirection, $reportColumnMap): int {
    $leftValue = $left[$sortColumn] ?? '';
    $rightValue = $right[$sortColumn] ?? '';
    if (($reportColumnMap[$sortColumn]['display_type'] ?? '') === 'date') {
      $leftValue = $leftValue !== '' ? strtotime((string) $leftValue) : 0;
      $rightValue = $rightValue !== '' ? strtotime((string) $rightValue) : 0;
    }
    if (is_numeric($leftValue) && is_numeric($rightValue)) {
      $result = ((float) $leftValue) <=> ((float) $rightValue);
    } else {
      $result = strnatcasecmp((string) $leftValue, (string) $rightValue);
    }
    return $sortDirection === 'desc' ? -$result : $result;
  });

  $reportAction = (string) ($_GET['action'] ?? '');
  if ($reportAction === 'export') {
    hub_report_stream_csv('inventory-' . date('Y-m-d') . '.csv', $exportColumns, $filteredRows);
    exit;
  }
  if ($reportAction === 'print') {
    hub_report_print_document($customerLabel . ' Inventory', hub_report_table_columns($reportColumns), $filteredRows);
    exit;
  }

  $totalRows = count($filteredRows);
  $totalPages = max(1, (int) ceil($totalRows / $perPage));
  $page = min($page, $totalPages);
  $offset = ($page - 1) * $perPage;
  $rows = array_slice($filteredRows, $offset, $perPage);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | <?php echo hub_h($customerLabel); ?> Portal Inventory</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="portal-page portal-report-page">
  <header class="portal-header">
    <a class="portal-logo" href="/dashboard.php" aria-label="RxSource Hub home">
      <img class="portal-logo-image" src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="RxSource Hub">
    </a>
    <div class="portal-welcome">
      <?php echo hub_h($portalDisplayName ?: 'User'); ?><?php echo $customerLabel !== 'No customer selected' ? ' [' . hub_h($customerLabel) . ']' : ''; ?>
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
      <a class="portal-admin-link" href="/faq.php?page=inventory" title="Help"><i class="<?php echo hub_h(hub_nav_icon_class('help')); ?>" aria-hidden="true"></i><span>Help</span></a>
      <a class="portal-admin-link" href="/logout.php" title="Logout"><i class="<?php echo hub_h(hub_nav_icon_class('logout')); ?>" aria-hidden="true"></i><span>Logout</span></a>
      <a class="portal-website-link" href="/dashboard.php" title="Back to Dashboard">Back to Dashboard</a>
    </div>
  </header>

  <main class="stack portal-section portal-content">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand">Portal Inventory</p>
          <h1><?php echo hub_h($customerLabel); ?> Inventory</h1>
          <p class="muted">Live Portal Inventory filtered by internal customer ID.</p>
        </div>
        <div class="links">
          <a href="/dashboard.php#proposals">Back to summary</a>
        </div>
      </div>
    </div>

    <?php if (!empty($atAGlanceSections)): ?>
      <div class="card">
        <div class="report-glance-fields report-glance-<?php echo hub_h($atAGlanceLayout); ?>">
          <?php foreach ($atAGlanceSections as $section): ?>
            <section class="report-glance-section">
              <h2 class="report-glance-heading">At a Glance: <span><?php echo hub_h((string) $section['heading']); ?></span></h2>
              <div class="stat-pills" style="--glance-pill-columns: <?php echo max(1, min(6, (int) ($section['option_count'] ?? 1))); ?>;">
                <?php foreach (($section['items'] ?? []) as $item): ?>
                  <?php $countVal = (int) ($item['count'] ?? 0); ?>
                  <a class="stat-pill report-glance-pill<?php echo !empty($item['is_active']) ? ' is-active' : ''; ?><?php echo $countVal === 0 ? ' is-zero' : ''; ?>" href="<?php echo hub_h((string) ($item['href'] ?? '#')); ?>" style="background: <?php echo hub_h((string) ($item['colour'] ?? '#1f9acb')); ?>;">
                    <div class="label"><?php echo hub_h(hub_report_format_value((string) ($item['label'] ?? ''), (string) ($section['display_type'] ?? 'text'))); ?></div>
                    <div class="value"><?php echo hub_h((string) $countVal); ?></div>
                  </a>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card" id="inventory-report">

      <form method="get" action="/client-portal-inventory.php" class="import-data-controls admin-table-controls" id="client-inventory-filter-form">
        <div>
          <label for="per_page">Rows per page</label>
          <select id="per_page" name="per_page" onchange="this.form.submit()">
            <?php foreach ([10, 25, 50, 100, 200] as $option): ?>
              <option value="<?php echo $option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="sort" value="<?php echo hub_h($sort); ?>">
        <div class="links import-data-reset">
          <?php if (hub_report_has_filters($columnFilters)): ?>
              <a href="<?php echo hub_h(hub_report_url('/client-portal-inventory.php', ['page' => 1, 'per_page' => $perPage, 'col' => []])); ?>">Clear Filters</a>
          <?php else: ?>
              <span class="disabled-action" aria-disabled="true">Clear Filters</span>
          <?php endif; ?>
        </div>
        <div class="links report-table-actions">
          <a class="but4" href="<?php echo hub_h(hub_report_url('/client-portal-inventory.php', ['action' => 'print'])); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-print" aria-hidden="true"></i> Print</a>
          <a class="but4" href="<?php echo hub_h(hub_report_url('/client-portal-inventory.php', ['action' => 'export'])); ?>"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Export</a>
        </div>
      </form>

      <div class="import-data-table-wrap">
        <table class="table portal-report-table import-data-table">
          <thead>
            <tr>
              <?php foreach ($columns as $column): ?>
                <th><?php echo hub_report_sort_link('/client-portal-inventory.php', $column, (string) ($reportColumnMap[$column]['heading'] ?? $column), $sort); ?></th>
              <?php endforeach; ?>
            </tr>
            <tr class="import-data-filter-row">
              <?php foreach ($columns as $column): ?>
                <th>
                  <?php if (($reportColumnMap[$column]['filter_type'] ?? 'search') === 'none'): ?>
                    <span class="muted">Filter</span>
                  <?php elseif (($reportColumnMap[$column]['filter_type'] ?? 'search') === 'select'): ?>
                    <select name="col[<?php echo hub_h($column); ?>]" form="client-inventory-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (array_keys($filterOptions[$column]) as $option): ?>
                        <option value="<?php echo hub_h($option); ?>" <?php echo (($columnFilters[$column] ?? '') === $option) ? 'selected' : ''; ?>>
                          <?php echo hub_h($option); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <input
                      name="col[<?php echo hub_h($column); ?>]"
                      form="client-inventory-filter-form"
                      type="search"
                      data-auto-filter
                      value="<?php echo hub_h((string) ($columnFilters[$column] ?? '')); ?>"
                      placeholder="Search">
                  <?php endif; ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="<?php echo count($columns); ?>">
                  <div class="alert info" style="margin:0;">No live Portal Inventory rows found for this user.</div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $rowIndex => $row): ?>
                <?php $reportModalId = 'inventory-report-detail-' . (int) ($row['id'] ?? $rowIndex); ?>
                <tr>
                  <?php foreach ($columns as $column): ?>
                    <td>
                      <?php if ($column === $reportLinkColumn): ?>
                        <button type="button" class="report-detail-link but3" data-open-report-modal="<?php echo hub_h($reportModalId); ?>"><?php echo hub_h(hub_report_format_value($row[$column] ?? '', (string) ($reportColumnMap[$column]['display_type'] ?? 'text')) ?: 'View'); ?></button>
                      <?php else: ?>
                        <?php echo hub_report_render_value($row[$column] ?? '', $reportColumnMap[$column] ?? []); ?>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalRows > 0): ?>
        <nav class="import-data-pagination" aria-label="Portal inventory pages">
          <div class="links">
            <?php if ($page > 1): ?>
              <a href="<?php echo hub_h(hub_report_url('/client-portal-inventory.php', ['page' => 1])); ?>">First</a>
              <a href="<?php echo hub_h(hub_report_url('/client-portal-inventory.php', ['page' => $page - 1])); ?>">Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></span>
            <?php if ($page < $totalPages): ?>
              <a href="<?php echo hub_h(hub_report_url('/client-portal-inventory.php', ['page' => $page + 1])); ?>">Next</a>
              <a href="<?php echo hub_h(hub_report_url('/client-portal-inventory.php', ['page' => $totalPages])); ?>">Last</a>
            <?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
      <div class="report-record-total">Total records selected: <?php echo number_format($totalRows); ?></div>
    </div>
  </main>
  <?php if ($reportLinkColumn !== ''): ?>
    <?php foreach ($rows as $rowIndex => $row): ?>
      <?php
        $reportModalId = 'inventory-report-detail-' . (int) ($row['id'] ?? $rowIndex);
        $reportTitleValue = hub_report_format_value($row[$reportLinkColumn] ?? '', (string) ($reportColumnMap[$reportLinkColumn]['display_type'] ?? 'text'));
        echo hub_report_detail_modal($reportModalId, 'Inventory Report' . ($reportTitleValue !== '' ? ': ' . $reportTitleValue : ''), $row, $reportDetailColumns);
      ?>
    <?php endforeach; ?>
  <?php endif; ?>
  <script>
    (function () {
      function closeReportModal(modal) {
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('report-modal-open', 'report-detail-printing');
      }
      document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-open-report-modal]');
        if (opener) {
          var modal = document.getElementById(opener.getAttribute('data-open-report-modal'));
          if (modal) {
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('report-modal-open');
          }
          return;
        }
        var close = event.target.closest('[data-close-report-modal]');
        if (close) {
          closeReportModal(close.closest('.report-detail-modal'));
          return;
        }
        var print = event.target.closest('[data-print-report-modal]');
        if (print) {
          document.body.classList.add('report-detail-printing');
          window.print();
        }
      });
      window.addEventListener('afterprint', function () { document.body.classList.remove('report-detail-printing'); });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeReportModal(document.querySelector('.report-detail-modal.open'));
      });
      var form = document.getElementById('client-inventory-filter-form');
      if (!form) return;
      var timer = null;
      function submitFilters() {
        var page = form.querySelector('input[name="page"]');
        if (page) page.value = '1';
        form.submit();
      }
      document.querySelectorAll('[data-auto-filter]').forEach(function (field) {
        if (field.tagName === 'SELECT') {
          field.addEventListener('change', submitFilters);
          return;
        }
        field.addEventListener('input', function () {
          clearTimeout(timer);
          timer = setTimeout(submitFilters, 550);
        });
        field.addEventListener('keydown', function (event) {
          if (event.key === 'Enter') {
            event.preventDefault();
            clearTimeout(timer);
            submitFilters();
          }
        });
      });
    })();
  </script>
</body>
</html>
