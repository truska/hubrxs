<?php
require_once __DIR__ . '/../includes/app/imports/remote_sources.php';
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_admin();

global $pdo, $DB_OK;

$sources = [];
$selectedSource = null;
$rows = [];
$columns = [];
$allRows = [];
$customerOptions = [];
$projectOptions = [];
$description2Options = [];
$description2Column = null;
$selectFilterOptions = [];
$totalRows = 0;
$latestFetch = null;

function hub_import_data_raw_table_for_source(?array $source): ?string {
  if (!$source) {
    return null;
  }
  $handler = (string) ($source['handler'] ?? '');
  if ($handler === 'portal_inventory') {
    return 'hub_inventory_raw';
  }
  if ($handler === 'so_portal_lines') {
    return 'hub_so_raw';
  }
  return null;
}

function hub_import_data_raw_count(?array $source): int {
  global $pdo, $DB_OK;

  $table = hub_import_data_raw_table_for_source($source);
  if (!$table || !$DB_OK || !($pdo instanceof PDO) || !hub_table_exists($table)) {
    return 0;
  }

  $stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '` WHERE source_id = :source_id');
  $stmt->execute([':source_id' => (int) ($source['id'] ?? 0)]);
  return (int) $stmt->fetchColumn();
}

if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_import_source')) {
  $stmtSources = $pdo->query(
    'SELECT s.id, s.name, s.import_key, s.handler
     FROM hub_import_source s
     WHERE s.archived = 0
     ORDER BY s.name ASC, s.id ASC'
  );
  $sources = $stmtSources ? ($stmtSources->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  foreach ($sources as &$sourceRow) {
    $sourceRow['row_count'] = hub_import_data_raw_count($sourceRow);
  }
  unset($sourceRow);
}

$sourceId = (int) ($_GET['source_id'] ?? 0);
if ($sourceId <= 0 && !empty($sources)) {
  $sourceId = (int) $sources[0]['id'];
}

foreach ($sources as $source) {
  if ((int) $source['id'] === $sourceId) {
    $selectedSource = $source;
    break;
  }
}

$columnFilters = is_array($_GET['col'] ?? null) ? $_GET['col'] : [];
$columnFilters = array_map(static function ($value): string {
  return trim((string) $value);
}, $columnFilters);

$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min(200, $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
$sortColumn = trim((string) ($_GET['sort_col'] ?? '#'));
$sortDirection = strtolower(trim((string) ($_GET['sort_dir'] ?? 'asc')));
if (!in_array($sortDirection, ['asc', 'desc'], true)) {
  $sortDirection = 'asc';
}

if ($selectedSource && $DB_OK && ($pdo instanceof PDO)) {
  $stmtFetch = $pdo->prepare(
    'SELECT f.*
     FROM hub_import_fetch f
     WHERE f.source_id = :source_id AND f.status = :status AND f.skipped = 0
     ORDER BY f.id DESC
     LIMIT 1'
  );
  $stmtFetch->execute([
    ':source_id' => $sourceId,
    ':status' => 'complete',
  ]);
  $latestFetch = $stmtFetch->fetch(PDO::FETCH_ASSOC) ?: null;

  $rawTable = hub_import_data_raw_table_for_source($selectedSource);
  $stmtRows = null;
  if ($rawTable && hub_table_exists($rawTable)) {
    $stmtRows = $pdo->prepare(
      'SELECT row_index, raw_json
       FROM `' . str_replace('`', '', $rawTable) . '`
       WHERE source_id = :source_id
       ORDER BY row_index ASC'
    );
    $stmtRows->execute([':source_id' => $sourceId]);
  }

  foreach ($stmtRows ? ($stmtRows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $payloadRow) {
    $decoded = json_decode((string) $payloadRow['raw_json'], true);
    if (!is_array($decoded)) {
      continue;
    }    $decoded = ['#' => (int) $payloadRow['row_index'] + 1] + $decoded;
    $allRows[] = $decoded;
    foreach (array_keys($decoded) as $column) {
      if (!in_array($column, $columns, true)) {
        $columns[] = $column;
      }
    }
  }

  foreach ($columns as $column) {
    if (hub_import_data_is_description_2_column((string) $column)) {
      $description2Column = (string) $column;
      break;
    }
  }

  foreach ($columns as $column) {
    $column = (string) $column;
    if (!hub_import_data_uses_select_filter($column)) {
      continue;
    }
    $selectFilterOptions[$column] = hub_import_data_distinct_options($allRows, $column, $columnFilters, $column);
    if (!empty($columnFilters[$column])) {
      $selectFilterOptions[$column][$columnFilters[$column]] = true;
    }
    ksort($selectFilterOptions[$column], SORT_NATURAL | SORT_FLAG_CASE);
  }

  $filteredRows = [];
  foreach ($allRows as $decoded) {
    if (!hub_import_data_row_matches_columns($decoded, $columnFilters)) {
      continue;
    }
    $filteredRows[] = $decoded;
  }

  if (!in_array($sortColumn, $columns, true)) {
    $sortColumn = in_array('#', $columns, true) ? '#' : (string) ($columns[0] ?? '');
  }
  usort($filteredRows, static function (array $left, array $right) use ($sortColumn, $sortDirection): int {
    $leftValue = $left[$sortColumn] ?? '';
    $rightValue = $right[$sortColumn] ?? '';
    if (is_array($leftValue)) {
      $leftValue = json_encode($leftValue, JSON_UNESCAPED_UNICODE);
    }
    if (is_array($rightValue)) {
      $rightValue = json_encode($rightValue, JSON_UNESCAPED_UNICODE);
    }
    if (is_numeric($leftValue) && is_numeric($rightValue)) {
      $result = (float) $leftValue <=> (float) $rightValue;
    } else {
      $result = strnatcasecmp((string) $leftValue, (string) $rightValue);
    }
    return $sortDirection === 'desc' ? -$result : $result;
  });

  ksort($customerOptions, SORT_NATURAL | SORT_FLAG_CASE);
  ksort($projectOptions, SORT_NATURAL | SORT_FLAG_CASE);
  ksort($description2Options, SORT_NATURAL | SORT_FLAG_CASE);
  $totalRows = count($filteredRows);
  $totalPages = max(1, (int) ceil($totalRows / $perPage));
  $page = min($page, $totalPages);
  $offset = ($page - 1) * $perPage;
  $rows = array_slice($filteredRows, $offset, $perPage);
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);

function hub_import_data_url(array $params): string {
  $current = [
    'source_id' => $_GET['source_id'] ?? '',
    'page' => $_GET['page'] ?? 1,
    'per_page' => $_GET['per_page'] ?? 50,
    'col' => $_GET['col'] ?? [],
    'sort_col' => $_GET['sort_col'] ?? '#',
    'sort_dir' => $_GET['sort_dir'] ?? 'asc',
  ];
  return '/admin/import-data.php?' . http_build_query(array_merge($current, $params));
}

function hub_import_data_sort_link(string $column, string $currentColumn, string $currentDirection): string {
  $isActive = $column === $currentColumn;
  $nextDirection = ($isActive && $currentDirection === 'asc') ? 'desc' : 'asc';
  $arrow = ' ↕';
  if ($isActive && $currentDirection === 'asc') {
    $arrow = ' ▲';
  } elseif ($isActive && $currentDirection === 'desc') {
    $arrow = ' ▼';
  }

  return '<a class="table-sort-link" href="' . hub_h(hub_import_data_url(['sort_col' => $column, 'sort_dir' => $nextDirection, 'page' => 1])) . '">' . hub_h($column) . $arrow . '</a>';
}

function hub_import_data_uses_select_filter(string $column): bool {
  return in_array(mb_strtolower(trim($column)), ['inventoryid', 'warehouseid', 'description_2'], true);
}

function hub_import_data_filter_select_options(array $options, string $selected): string {
  $html = '<option value="">All</option>';
  foreach (array_keys($options) as $option) {
    $isSelected = ($selected === (string) $option) ? ' selected' : '';
    $html .= '<option value="' . hub_h((string) $option) . '"' . $isSelected . '>' . hub_h((string) $option) . '</option>';
  }
  return $html;
}

function hub_import_data_row_matches_columns(array $row, array $filters): bool {
  return hub_import_data_row_matches_columns_except($row, $filters, null);
}

function hub_import_data_row_matches_columns_except(array $row, array $filters, ?string $exceptColumn): bool {
  foreach ($filters as $column => $filter) {
    $column = (string) $column;
    if ($exceptColumn !== null && $column === $exceptColumn) {
      continue;
    }
    $filter = trim((string) $filter);
    if ($filter === '') {
      continue;
    }

    $value = $row[$column] ?? '';
    if (is_array($value)) {
      $value = json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    if (hub_import_data_uses_select_filter($column)) {
      if (trim((string) $value) !== $filter) {
        return false;
      }
      continue;
    }

    if (mb_strpos(mb_strtolower((string) $value), mb_strtolower($filter)) === false) {
      return false;
    }
  }

  return true;
}

function hub_import_data_is_description_2_column(string $column): bool {
  return mb_strtolower(trim($column)) === 'description_2';
}

function hub_import_data_is_portal_inventory_source(?array $source): bool {
  if (!$source) {
    return false;
  }

  $haystack = mb_strtolower(
    (string) ($source['handler'] ?? '') . ' ' .
    (string) ($source['import_key'] ?? '') . ' ' .
    (string) ($source['name'] ?? '')
  );

  return mb_strpos($haystack, 'portal_inventory') !== false
    || (mb_strpos($haystack, 'portal') !== false && mb_strpos($haystack, 'inventory') !== false);
}

function hub_import_data_distinct_options(array $rows, string $column, array $filters, ?string $exceptColumn = null): array {
  $options = [];
  foreach ($rows as $row) {
    if (!hub_import_data_row_matches_columns_except($row, $filters, $exceptColumn)) {
      continue;
    }
    $value = trim((string) ($row[$column] ?? ''));
    if ($value !== '') {
      $options[$value] = true;
    }
  }
  return $options;
}

function hub_import_data_has_filters(array $filters): bool {
  foreach ($filters as $filter) {
    if (trim((string) $filter) !== '') {
      return true;
    }
  }
  return false;
}

function hub_import_data_cell($value): string {
  if (is_array($value)) {
    return hub_h(json_encode($value, JSON_UNESCAPED_UNICODE));
  }
  if ($value === null) {
    return '';
  }
  return hub_h((string) $value);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Import Data</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
  <?php echo hub_admin_header('admin'); ?>
  <div class="stack">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand">Admin</p>
          <h1>Import data</h1>
          <p class="muted">View the latest staged JSON rows in a readable table.</p>
        </div>
        <div class="links">
          <a href="/admin.php">Back to admin</a>
          <a href="/admin/import-sources.php">Import sources</a>
          <a href="/dashboard.php">Dashboard</a>
        </div>
      </div>

      <?php if (empty($sources)): ?>
        <div class="alert info">No import sources with staged data were found.</div>
      <?php else: ?>
        <form method="get" action="/admin/import-data.php" class="import-data-controls" id="import-data-filter-form">
          <div>
            <label for="source_id">Import feed</label>
            <select id="source_id" name="source_id" onchange="this.form.submit()">
              <?php foreach ($sources as $source): ?>
                <option value="<?php echo (int) $source['id']; ?>" <?php echo ((int) $source['id'] === $sourceId) ? 'selected' : ''; ?>>
                  <?php echo hub_h((string) $source['name']); ?> (<?php echo (int) $source['row_count']; ?> rows)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="per_page">Rows per page</label>
            <select id="per_page" name="per_page" onchange="this.form.submit()">
              <?php foreach ([10, 25, 50, 100, 200] as $option): ?>
                <option value="<?php echo $option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <input type="hidden" name="page" value="1">
          <input type="hidden" name="sort_col" value="<?php echo hub_h($sortColumn); ?>">
          <input type="hidden" name="sort_dir" value="<?php echo hub_h($sortDirection); ?>">
          <?php if (hub_import_data_has_filters($columnFilters)): ?>
            <div class="links import-data-reset">
              <a href="/admin/import-data.php?source_id=<?php echo (int) $sourceId; ?>&per_page=<?php echo (int) $perPage; ?>">Reset filters</a>
            </div>
          <?php endif; ?>
        </form>

        <?php if ($selectedSource): ?>
          <div class="import-data-summary">
            <span><strong><?php echo hub_h((string) $selectedSource['name']); ?></strong></span>
            <span><?php echo hub_h((string) $selectedSource['handler']); ?></span>
            <span><?php echo number_format($totalRows); ?> matching rows</span>
            <span><?php echo number_format(count($allRows)); ?> staged rows</span>
            <?php if ($latestFetch): ?>
              <span>Fetch #<?php echo (int) $latestFetch['id']; ?></span>
              <span><?php echo hub_h((string) $latestFetch['finished_at']); ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (empty($columns)): ?>
          <div class="alert info">No rows found for this source.</div>
        <?php else: ?>
          <div class="import-data-table-wrap">
            <table class="table table-sm align-middle admin-data-table import-data-table">
              <thead>
                <tr>
                  <?php foreach ($columns as $column): ?>
                    <th scope="col"><?php echo hub_import_data_sort_link((string) $column, $sortColumn, $sortDirection); ?></th>
                  <?php endforeach; ?>
                </tr>
                <tr class="import-data-filter-row">
                  <?php foreach ($columns as $column): ?>
                    <th>
                      <?php if (hub_import_data_uses_select_filter((string) $column)): ?>
                        <select name="col[<?php echo hub_h((string) $column); ?>]" form="import-data-filter-form" data-auto-filter>
                          <?php echo hub_import_data_filter_select_options($selectFilterOptions[(string) $column] ?? [], (string) ($columnFilters[$column] ?? "")); ?>
                        </select>
                      <?php else: ?>
                        <input
                          name="col[<?php echo hub_h((string) $column); ?>]"
                          form="import-data-filter-form"
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
                      <div class="alert info" style="margin:0;">No rows match the current filters.</div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($rows as $row): ?>
                    <tr>
                      <?php foreach ($columns as $column): ?>
                        <td><?php echo hub_import_data_cell($row[$column] ?? null); ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalRows > 0): ?>
            <nav class="import-data-pagination" aria-label="Import data pages">
              <div class="links">
                <?php if ($page > 1): ?>
                  <a href="<?php echo hub_h(hub_import_data_url(['page' => 1])); ?>">First</a>
                  <a href="<?php echo hub_h(hub_import_data_url(['page' => $page - 1])); ?>">Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></span>
                <?php if ($page < $totalPages): ?>
                  <a href="<?php echo hub_h(hub_import_data_url(['page' => $page + 1])); ?>">Next</a>
                  <a href="<?php echo hub_h(hub_import_data_url(['page' => $totalPages])); ?>">Last</a>
                <?php endif; ?>
              </div>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <script>
    (function () {
      var form = document.getElementById('import-data-filter-form');
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
