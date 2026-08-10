<?php
require_once __DIR__ . '/auth.php';

function hub_report_column_defaults(string $reportKey): array {
  $defaults = [
    'shipping' => [
      ['OrderNbr', 'order_nbr', 'Order #', 'text', 'search', 0, 100],
      ['Description', 'description', 'Description', 'text', 'search', 0, 200],
      ['Status', 'status', 'Status', 'text', 'select', 1, 300],
      ['RequestedOn', 'requested_on', 'Requested On', 'date', 'search', 0, 400],
      ['ShipmentDate', 'shipment_date', 'Shipment Date', 'date', 'search', 0, 500],
      ['LineDescription', 'line_description', 'Product Details', 'text', 'search', 0, 600],
      ['ShipVia', 'ship_via', 'Courier', 'text', 'select', 0, 700],
      ['TrackingNumber', 'tracking_number', 'Tracking Number', 'text', 'search', 0, 800],
      ['LotSerialNbr', 'lot_serial_nbr', 'Lot #', 'text', 'search', 0, 900],
      ['Quantity', 'quantity', 'Quantity', 'number', 'search', 0, 1000],
      ['BranchName', 'branch_name', 'Branch Name', 'text', 'select', 0, 1100],
    ],
    'inventory' => [
      ['InventoryID', 'InventoryID', 'Inventory ID', 'text', 'select', 0, 100],
      ['Description', 'Description', 'Description', 'text', 'search', 0, 200],
      ['LotSerialNbr', 'LotSerialNbr', 'Lot #', 'text', 'search', 0, 300],
      ['ExpiryDate', 'ExpiryDate', 'Expiry Date', 'date', 'search', 0, 400],
      ['QtyOnHand', 'QtyOnHand', 'Quantity On Hand', 'number', 'search', 0, 500],
      ['QtyAvailable', 'QtyAvailable', 'Quantity Available', 'number', 'search', 0, 600],
      ['WarehouseID', 'WarehouseID', 'Warehouse Location', 'text', 'select', 1, 700],
    ],
  ];

  $columns = [];
  foreach (($defaults[$reportKey] ?? []) as $default) {
    $columns[] = [
      'report_key' => $reportKey,
      'column_key' => $default[1],
      'source_field' => $default[0],
      'data_field' => $default[1],
      'heading' => $default[2],
      'display_type' => $default[3],
      'filter_type' => $default[4],
      'show_on_web' => 1,
      'at_a_glance' => $default[5],
      'is_link' => 0,
      'is_report' => 0,
      'is_table' => 1,
      'is_export' => 1,
      'sort' => $default[6],
    ];
  }
  return $columns;
}

function hub_report_columns(string $reportKey): array {
  global $pdo, $DB_OK;

  if (
    $DB_OK
    && ($pdo instanceof PDO)
    && hub_table_exists('hub_report_column')
  ) {
    $stmt = $pdo->prepare(
      'SELECT report_key, column_key, source_field, data_field, heading, display_type, filter_type,
              show_on_web, at_a_glance, is_link, is_report, is_table, is_export, sort
       FROM hub_report_column
       WHERE report_key = :report_key
         AND archived = 0
         AND show_on_web = 1
       ORDER BY sort ASC, heading ASC'
    );
    $stmt->execute([':report_key' => $reportKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!empty($rows)) {
      return $rows;
    }
  }

  return hub_report_column_defaults($reportKey);
}

function hub_report_table_columns(array $columns): array {
  return array_values(array_filter($columns, static function (array $column): bool {
    return !empty($column['is_table']);
  }));
}

function hub_report_detail_columns(array $columns): array {
  return array_values(array_filter($columns, static function (array $column): bool {
    return !empty($column['is_report']);
  }));
}

function hub_report_export_columns(array $columns): array {
  return array_values(array_filter($columns, static function (array $column): bool {
    return !empty($column['is_export']);
  }));
}

function hub_report_output_columns(string $reportKey, string $flag): array {
  global $pdo, $DB_OK;

  if (!in_array($flag, ['is_report', 'is_table', 'is_export'], true)) {
    return [];
  }
  if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_report_column')) {
    $stmt = $pdo->prepare(
      'SELECT report_key, column_key, source_field, data_field, heading, display_type, filter_type,
              show_on_web, at_a_glance, is_link, is_report, is_table, is_export, sort
       FROM hub_report_column
       WHERE report_key = :report_key
         AND archived = 0
         AND ' . $flag . ' = 1
       ORDER BY sort ASC, heading ASC'
    );
    $stmt->execute([':report_key' => $reportKey]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  return array_values(array_filter(hub_report_column_defaults($reportKey), static function (array $column) use ($flag): bool {
    return !empty($column[$flag]);
  }));
}

function hub_report_merge_columns(array ...$columnSets): array {
  $merged = [];
  foreach ($columnSets as $columns) {
    foreach ($columns as $column) {
      $key = (string) ($column['column_key'] ?? $column['data_field'] ?? '');
      if ($key !== '') $merged[$key] = $column;
    }
  }
  return array_values($merged);
}

function hub_report_stream_csv(string $filename, array $columns, array $rows): void {
  $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $filename) ?: 'report.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('X-Content-Type-Options: nosniff');
  $output = fopen('php://output', 'wb');
  if ($output === false) return;
  fwrite($output, "\xEF\xBB\xBF");
  fputcsv($output, array_map(static function (array $column): string {
    return (string) ($column['heading'] ?? $column['column_key'] ?? '');
  }, $columns), ',', '"', '');
  foreach ($rows as $row) {
    $values = [];
    foreach ($columns as $column) {
      $columnKey = (string) ($column['column_key'] ?? $column['data_field'] ?? '');
      $value = hub_report_format_value($row[$columnKey] ?? '', (string) ($column['display_type'] ?? 'text'));
      $values[] = preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }
    fputcsv($output, $values, ',', '"', '');
  }
  fclose($output);
}

function hub_report_print_document(string $title, array $columns, array $rows): void {
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo hub_h($title); ?></title>
    <style>
      body { margin: 24px; color: #1a1a1a; background: #fff; font: 12px/1.4 Arial, sans-serif; }
      h1 { margin: 0 0 16px; font-size: 22px; }
      table { width: 100%; border-collapse: collapse; }
      th, td { padding: 7px 8px; border: 1px solid #bfc5c8; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
      th { background: #26333b; color: #fff; }
      tbody tr:nth-child(even) { background: #f1f3f4; }
      .report-meta { margin: 0 0 12px; color: #4a5660; }
      @page { size: landscape; margin: 12mm; }
    </style>
  </head>
  <body>
    <h1><?php echo hub_h($title); ?></h1>
    <p class="report-meta"><?php echo number_format(count($rows)); ?> record<?php echo count($rows) === 1 ? '' : 's'; ?></p>
    <table>
      <thead><tr><?php foreach ($columns as $column): ?><th><?php echo hub_h((string) ($column['heading'] ?? $column['column_key'] ?? '')); ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?><tr>
          <?php foreach ($columns as $column): ?>
            <?php $columnKey = (string) ($column['column_key'] ?? $column['data_field'] ?? ''); ?>
            <td><?php echo hub_h(hub_report_format_value($row[$columnKey] ?? '', (string) ($column['display_type'] ?? 'text'))); ?></td>
          <?php endforeach; ?>
        </tr><?php endforeach; ?>
      </tbody>
    </table>
    <script>window.addEventListener('load', function () { window.print(); });</script>
  </body>
  </html>
  <?php
}

function hub_report_detail_modal(string $modalId, string $title, array $row, array $columns): string {
  ob_start();
  ?>
  <div class="modal report-detail-modal" id="<?php echo hub_h($modalId); ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo hub_h($modalId); ?>_title" aria-hidden="true">
    <div class="modal-content report-detail-modal-content">
      <div class="modal-header report-detail-header">
        <h2 id="<?php echo hub_h($modalId); ?>_title"><?php echo hub_h($title); ?></h2>
        <button type="button" class="close-btn but2" data-close-report-modal aria-label="Close report">&times;</button>
      </div>
      <div class="report-detail-fields">
        <?php if (empty($columns)): ?>
          <div class="alert info">No columns are currently selected for this report. Select Report against the required columns in Report Columns.</div>
        <?php else: ?>
          <?php foreach ($columns as $column): ?>
            <?php
              $columnKey = (string) ($column['column_key'] ?? $column['data_field'] ?? '');
              $heading = trim((string) ($column['heading'] ?? $columnKey));
              $rendered = hub_report_render_value($row[$columnKey] ?? '', $column);
            ?>
            <div class="report-detail-field">
              <div class="report-detail-label"><?php echo hub_h($heading); ?>:</div>
              <div class="report-detail-value"><?php echo $rendered !== '' ? $rendered : '<span class="muted">-</span>'; ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="links report-detail-actions">
        <button type="button" class="but2" data-close-report-modal>Close</button>
        <button type="button" data-print-report-modal><i class="fa-solid fa-print" aria-hidden="true"></i> Print</button>
      </div>
    </div>
  </div>
  <?php
  return (string) ob_get_clean();
}

function hub_report_column_keys(array $columns): array {
  return array_map(static function (array $column): string {
    return (string) ($column['column_key'] ?? $column['data_field'] ?? '');
  }, $columns);
}

function hub_report_column_by_key(array $columns): array {
  $map = [];
  foreach ($columns as $column) {
    $key = (string) ($column['column_key'] ?? $column['data_field'] ?? '');
    if ($key !== '') {
      $map[$key] = $column;
    }
  }
  return $map;
}

function hub_report_select_filter_fields(array $columns): array {
  $fields = [];
  foreach ($columns as $column) {
    if (($column['filter_type'] ?? '') === 'select') {
      $key = (string) ($column['column_key'] ?? $column['data_field'] ?? '');
      if ($key !== '') {
        $fields[] = $key;
      }
    }
  }
  return $fields;
}

function hub_report_at_a_glance_columns(array $columns): array {
  return array_values(array_filter($columns, static function (array $column): bool {
    return !empty($column['at_a_glance']);
  }));
}

function hub_report_value_key(string $value): string {
  $value = trim($value);
  return mb_strtolower($value === '' ? 'Unknown' : $value);
}

function hub_report_fallback_colour(string $valueKey): string {
  $palette = array_keys(hub_report_secondary_colour_palette());
  $index = abs((int) crc32($valueKey)) % count($palette);
  return $palette[$index];
}

function hub_report_secondary_colour_palette(): array {
  // Temporary fallback palette. Replace the labels/hex values here when the
  // client's official secondary colour palette is supplied.
  return [
    '#1f9acb' => 'Blue',
    '#0ec27a' => 'Green',
    '#ff8c00' => 'Orange',
    '#a855f7' => 'Purple',
    '#ef4444' => 'Red',
    '#14b8a6' => 'Teal',
    '#f97316' => 'Deep Orange',
    '#6366f1' => 'Indigo',
  ];
}

function hub_report_value_colour_defaults(string $reportKey, string $columnKey): array {
  // Explicit settings are stored in hub_report_value_colour. Any value without
  // a saved setting receives a deterministic colour from the secondary palette.
  return [];
}

function hub_report_value_colour_configs(string $reportKey, string $columnKey): array {
  global $pdo, $DB_OK;

  $configs = hub_report_value_colour_defaults($reportKey, $columnKey);
  if (
    $DB_OK
    && ($pdo instanceof PDO)
    && hub_table_exists('hub_report_value_colour')
  ) {
    $stmt = $pdo->prepare(
      'SELECT value_key, display_label, colour, sort, show_when_zero
       FROM hub_report_value_colour
       WHERE report_key = :report_key
         AND column_key = :column_key
         AND show_on_web = 1
         AND archived = 0
       ORDER BY sort ASC, display_label ASC, value_key ASC'
    );
    $stmt->execute([
      ':report_key' => $reportKey,
      ':column_key' => $columnKey,
    ]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $valueKey = hub_report_value_key((string) ($row['value_key'] ?? ''));
      if ($valueKey === '') {
        continue;
      }
      $label = trim((string) ($row['display_label'] ?? ''));
      $configs[$valueKey] = [
        'label' => $label !== '' ? $label : (string) ($row['value_key'] ?? ''),
        'colour' => trim((string) ($row['colour'] ?? '')),
        'sort' => (int) ($row['sort'] ?? 100),
        'show_when_zero' => (int) ($row['show_when_zero'] ?? 1),
      ];
    }
  }

  return $configs;
}

function hub_report_at_a_glance_filter_url(string $baseUrl, array $currentFilters, string $columnKey, string $value, bool $isActive): string {
  $nextFilters = $currentFilters;
  if ($isActive) {
    unset($nextFilters[$columnKey]);
  } else {
    $nextFilters[$columnKey] = $value;
  }

  return hub_report_url($baseUrl, [
    'page' => 1,
    'col' => $nextFilters,
  ]);
}

function hub_report_at_a_glance_sections(string $reportKey, array $columns, array $allRows, array $filteredRows, array $currentFilters, string $baseUrl): array {
  $sections = [];
  foreach (hub_report_at_a_glance_columns($columns) as $column) {
    $columnKey = (string) ($column['column_key'] ?? '');
    if ($columnKey === '') {
      continue;
    }

    $configs = hub_report_value_colour_configs($reportKey, $columnKey);
    $items = [];
    foreach ($configs as $valueKey => $config) {
      if (empty($config['show_when_zero'])) {
        continue;
      }
      $items[$valueKey] = [
        'label' => (string) ($config['label'] ?? $valueKey),
        'value' => (string) ($config['label'] ?? $valueKey),
        'count' => 0,
        'colour' => (string) ($config['colour'] ?? ''),
        'sort' => (int) ($config['sort'] ?? 100),
      ];
    }

    foreach ($allRows as $row) {
      $label = trim((string) ($row[$columnKey] ?? ''));
      if ($label === '') {
        $label = 'Unknown';
      }
      $valueKey = hub_report_value_key($label);
      if (!isset($items[$valueKey])) {
        $config = $configs[$valueKey] ?? [];
        $items[$valueKey] = [
          'label' => (string) ($config['label'] ?? $label),
          'value' => $label,
          'count' => 0,
          'colour' => (string) ($config['colour'] ?? ''),
          'sort' => (int) ($config['sort'] ?? 1000),
        ];
      } else {
        $items[$valueKey]['value'] = $label;
      }
    }
    if (empty($items)) {
      continue;
    }

    foreach ($filteredRows as $row) {
      $label = trim((string) ($row[$columnKey] ?? ''));
      if ($label === '') {
        $label = 'Unknown';
      }
      $valueKey = hub_report_value_key($label);
      if (!isset($items[$valueKey])) {
        $items[$valueKey] = [
          'label' => $label,
          'value' => $label,
          'count' => 0,
          'colour' => '',
          'sort' => 1000,
        ];
      }
      $items[$valueKey]['count']++;
    }

    $activeValue = trim((string) ($currentFilters[$columnKey] ?? ''));
    foreach ($items as $valueKey => $item) {
      $isActive = $activeValue !== '' && hub_report_value_key($activeValue) === $valueKey;
      $value = (string) ($item['value'] ?? $item['label'] ?? $valueKey);
      $items[$valueKey]['colour'] = trim((string) ($item['colour'] ?? '')) ?: hub_report_fallback_colour($valueKey);
      $items[$valueKey]['is_active'] = $isActive;
      $items[$valueKey]['href'] = hub_report_at_a_glance_filter_url($baseUrl, $currentFilters, $columnKey, $value, $isActive);
    }

    uasort($items, static function (array $left, array $right): int {
      $sort = ((int) ($left['sort'] ?? 1000)) <=> ((int) ($right['sort'] ?? 1000));
      return $sort !== 0 ? $sort : strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    $sections[] = [
      'heading' => (string) ($column['heading'] ?? $columnKey),
      'display_type' => (string) ($column['display_type'] ?? 'text'),
      'option_count' => max(1, count($items)),
      'items' => array_values($items),
    ];
  }
  return $sections;
}

function hub_report_at_a_glance_layout(array $sections): string {
  if (count($sections) <= 1) {
    return 'single';
  }
  $totalOptions = 0;
  foreach ($sections as $section) {
    $totalOptions += (int) ($section['option_count'] ?? 0);
  }
  return $totalOptions <= 6 ? 'compact' : 'stacked';
}

function hub_report_has_filters(array $filters): bool {
  foreach ($filters as $filter) {
    if (trim((string) $filter) !== '') {
      return true;
    }
  }
  return false;
}

function hub_report_json_value(array $row, string $wantedKey): string {
  foreach ($row as $key => $value) {
    if (mb_strtolower((string) $key) === mb_strtolower($wantedKey)) {
      return trim((string) $value);
    }
  }
  return '';
}

function hub_report_format_value($value, string $displayType): string {
  $value = trim((string) $value);
  if ($value === '') {
    return '';
  }
  if ($displayType === 'date') {
    try {
      return (new DateTime($value))->format('m/d/Y');
    } catch (Throwable $e) {
      return $value;
    }
  }
  if ($displayType === 'number' && is_numeric($value)) {
    $number = (float) $value;
    return rtrim(rtrim(number_format($number, 4), '0'), '.');
  }
  return $value;
}

function hub_report_render_value($value, array $column): string {
  $displayType = !empty($column['is_link']) ? 'link' : (string) ($column['display_type'] ?? 'text');
  $formatted = hub_report_format_value($value, $displayType);
  if ($formatted === '') {
    return '';
  }
  if ($displayType === 'link' && filter_var($formatted, FILTER_VALIDATE_URL)) {
    $scheme = strtolower((string) parse_url($formatted, PHP_URL_SCHEME));
    if (in_array($scheme, ['http', 'https'], true)) {
      return '<a href="' . hub_h($formatted) . '" target="_blank" rel="noopener noreferrer">' . hub_h($formatted) . '</a>';
    }
  }
  return hub_h($formatted);
}

function hub_report_row_matches_filters(array $row, array $filters, array $columnMap, ?string $exceptColumn = null): bool {
  foreach ($filters as $column => $filter) {
    $column = (string) $column;
    if ($exceptColumn !== null && $column === $exceptColumn) {
      continue;
    }
    if (!isset($columnMap[$column])) {
      continue;
    }
    $filter = trim((string) $filter);
    if ($filter === '') {
      continue;
    }
    $value = (string) ($row[$column] ?? '');
    if (($columnMap[$column]['filter_type'] ?? '') === 'select') {
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

function hub_report_sort_link(string $baseUrl, string $column, string $label, string $currentSort): string {
  $ascKey = $column . '_asc';
  $descKey = $column . '_desc';
  $next = ($currentSort === $ascKey) ? $descKey : $ascKey;
  $arrow = ' ↕';
  if ($currentSort === $ascKey) {
    $arrow = ' ▲';
  } elseif ($currentSort === $descKey) {
    $arrow = ' ▼';
  }

  return '<a href="' . hub_h(hub_report_url($baseUrl, ['sort' => $next, 'page' => 1])) . '">' . hub_h($label) . $arrow . '</a>';
}

function hub_report_url(string $baseUrl, array $params): string {
  $current = [
    'page' => $_GET['page'] ?? 1,
    'per_page' => $_GET['per_page'] ?? 50,
    'col' => $_GET['col'] ?? [],
    'sort' => $_GET['sort'] ?? '',
  ];
  return $baseUrl . '?' . http_build_query(array_merge($current, $params));
}
