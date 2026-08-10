<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/report_columns.php';
hub_require_super_admin();

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();
$reportOptions = [
  'shipping' => 'Shipping',
  'inventory' => 'Inventory',
];
$displayTypes = ['text' => 'Text', 'date' => 'Date', 'number' => 'Number', 'link' => 'Link', 'badge' => 'Badge'];
$filterTypes = ['none' => 'None', 'search' => 'Search', 'select' => 'Select', 'date' => 'Date'];

function hub_admin_report_columns_bool(string $key): int {
  return isset($_POST[$key]) ? 1 : 0;
}

function hub_admin_report_imported_fields(string $reportKey): array {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO)) return [];
  $table = $reportKey === 'shipping' ? 'hub_so_live' : 'hub_inventory_live';
  $jsonColumn = $reportKey === 'shipping' ? 'raw_json' : 'json_data';
  if (!hub_table_exists($table) || !hub_table_column_exists($table, $jsonColumn)) return [];

  $fields = [];
  try {
    $stmt = $pdo->query('SELECT `' . $jsonColumn . '` FROM `' . $table . '` WHERE archived = 0 ORDER BY id DESC LIMIT 200');
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [] as $jsonValue) {
      $row = json_decode((string) $jsonValue, true);
      if (!is_array($row)) continue;
      foreach (array_keys($row) as $field) {
        $field = trim((string) $field);
        if ($field !== '') $fields[$field] = true;
      }
    }
  } catch (PDOException $e) {
    return [];
  }
  $fields = array_keys($fields);
  natcasesort($fields);
  return array_values($fields);
}

if (
  ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
  && isset($_POST['action'])
  && in_array($_POST['action'], ['update_report_column', 'add_report_column', 'archive_report_column'], true)
) {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_report_column')) {
    $error = 'Report column settings are not available.';
  } elseif ($_POST['action'] === 'archive_report_column') {
    $columnId = (int) ($_POST['column_id'] ?? 0);
    if ($columnId <= 0) {
      $error = 'Column not found.';
    } else {
      $stmt = $pdo->prepare('UPDATE hub_report_column SET archived = 1, show_on_web = 0, modified = NOW() WHERE id = :id LIMIT 1');
      $stmt->execute([':id' => $columnId]);
      hub_flash('success', 'Report column removed.');
      hub_redirect('/admin/report-columns.php');
    }
  } else {
    $columnId = (int) ($_POST['column_id'] ?? 0);
    $reportKey = trim((string) ($_POST['report_key'] ?? ''));
    $columnKey = trim((string) ($_POST['column_key'] ?? ''));
    $sourceField = trim((string) ($_POST['source_field'] ?? ''));
    $dataField = trim((string) ($_POST['data_field'] ?? ''));
    $heading = trim((string) ($_POST['heading'] ?? ''));
    $displayType = array_key_exists((string) ($_POST['display_type'] ?? ''), $displayTypes) ? (string) $_POST['display_type'] : 'text';
    $filterType = array_key_exists((string) ($_POST['filter_type'] ?? ''), $filterTypes) ? (string) $_POST['filter_type'] : 'search';
    $sort = (int) ($_POST['sort'] ?? 100);

    if ($sourceField !== '') {
      if ($dataField === '') $dataField = $sourceField;
      if ($columnKey === '') $columnKey = $dataField;
    }

    if (!array_key_exists($reportKey, $reportOptions) || $columnKey === '' || $sourceField === '' || $dataField === '' || $heading === '') {
      $error = 'Report, field names, and heading are required.';
    } else {
      try {
        if ($_POST['action'] === 'add_report_column') {
          $stmt = $pdo->prepare(
            'INSERT INTO hub_report_column
              (report_key, column_key, source_field, data_field, heading, display_type, filter_type, show_on_web, at_a_glance, is_link, is_report, is_table, is_export, sort, archived, created, modified)
             VALUES
              (:report_key, :column_key, :source_field, :data_field, :heading, :display_type, :filter_type, :show_on_web, :at_a_glance, :is_link, :is_report, :is_table, :is_export, :sort, 0, NOW(), NOW())'
          );
          $params = [
            ':report_key' => $reportKey,
            ':column_key' => $columnKey,
            ':source_field' => $sourceField,
            ':data_field' => $dataField,
            ':heading' => $heading,
            ':display_type' => $displayType,
            ':filter_type' => $filterType,
            ':show_on_web' => hub_admin_report_columns_bool('show_on_web'),
            ':at_a_glance' => hub_admin_report_columns_bool('at_a_glance'),
            ':is_link' => hub_admin_report_columns_bool('is_link'),
            ':is_report' => hub_admin_report_columns_bool('is_report'),
            ':is_table' => hub_admin_report_columns_bool('is_table'),
            ':is_export' => hub_admin_report_columns_bool('is_export'),
            ':sort' => $sort,
          ];
          $stmt->execute($params);
        } else {
          if ($columnId <= 0) {
            $error = 'Column not found.';
          } else {
            $stmt = $pdo->prepare(
              'UPDATE hub_report_column
               SET report_key = :report_key,
                   column_key = :column_key,
                   source_field = :source_field,
                   data_field = :data_field,
                   heading = :heading,
                   display_type = :display_type,
                   filter_type = :filter_type,
                   show_on_web = :show_on_web,
                   at_a_glance = :at_a_glance,
                   is_link = :is_link,
                   is_report = :is_report,
                   is_table = :is_table,
                   is_export = :is_export,
                   sort = :sort,
                   modified = NOW()
               WHERE id = :id
               LIMIT 1'
            );
            $params = [
              ':report_key' => $reportKey,
              ':column_key' => $columnKey,
              ':source_field' => $sourceField,
              ':data_field' => $dataField,
              ':heading' => $heading,
              ':display_type' => $displayType,
              ':filter_type' => $filterType,
              ':show_on_web' => hub_admin_report_columns_bool('show_on_web'),
              ':at_a_glance' => hub_admin_report_columns_bool('at_a_glance'),
              ':is_link' => hub_admin_report_columns_bool('is_link'),
              ':is_report' => hub_admin_report_columns_bool('is_report'),
              ':is_table' => hub_admin_report_columns_bool('is_table'),
              ':is_export' => hub_admin_report_columns_bool('is_export'),
              ':sort' => $sort,
              ':id' => $columnId,
            ];
            $stmt->execute($params);
          }
        }
        if ($error === null) {
          hub_flash('success', 'Report column saved.');
          hub_redirect('/admin/report-columns.php');
        }
      } catch (PDOException $e) {
        $error = 'Unable to save report column. The column key may already exist for this report.';
      }
    }
  }
}

$columns = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_report_column')) {
  $stmtColumns = $pdo->query(
    'SELECT *
     FROM hub_report_column
     WHERE archived = 0
     ORDER BY report_key ASC, sort ASC, heading ASC'
  );
  $columns = $stmtColumns ? ($stmtColumns->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
$availableFields = [
  'shipping' => hub_admin_report_imported_fields('shipping'),
  'inventory' => hub_admin_report_imported_fields('inventory'),
];
foreach ($columns as $configuredColumn) {
  $reportKey = (string) ($configuredColumn['report_key'] ?? '');
  $sourceField = trim((string) ($configuredColumn['source_field'] ?? ''));
  if (isset($availableFields[$reportKey]) && $sourceField !== '' && !in_array($sourceField, $availableFields[$reportKey], true)) {
    $availableFields[$reportKey][] = $sourceField;
    natcasesort($availableFields[$reportKey]);
    $availableFields[$reportKey] = array_values($availableFields[$reportKey]);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Report Columns</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
  <?php echo hub_admin_header('super'); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Reports</p>
            <h1>Report Columns</h1>
            <p class="muted">Manage front-end report headings, filters, visibility, order, and At a Glance flags.</p>
          </div>
          <div class="links">
            <a href="/super.php">Back to Super</a>
            <button type="button" data-open-modal="reportColumnAddModal">Add Column</button>
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
        <?php if (empty($columns)): ?>
          <div class="alert info">No report columns found. Run the schema installer to seed defaults.</div>
        <?php else: ?>
          <div class="import-data-table-wrap">
            <table class="table import-data-table">
              <thead>
                <tr>
                  <th>Report</th>
                  <th>Sort</th>
                  <th>Heading</th>
                  <th>Source Field</th>
                  <th>Data Field</th>
                  <th>Filter</th>
                  <th>Link</th>
                  <th>Report</th>
                  <th>Table</th>
                  <th>Export</th>
                  <th>At a Glance</th>
                  <th>Live</th>
                  <th class="table-actions-heading">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($columns as $column): ?>
                  <?php $modalId = 'reportColumnModal' . (int) $column['id']; ?>
                  <tr>
                    <td><?php echo hub_h($reportOptions[(string) $column['report_key']] ?? (string) $column['report_key']); ?></td>
                    <td><?php echo (int) $column['sort']; ?></td>
                    <td><?php echo hub_h((string) $column['heading']); ?></td>
                    <td><?php echo hub_h((string) $column['source_field']); ?></td>
                    <td><?php echo hub_h((string) $column['data_field']); ?></td>
                    <td><?php echo hub_h($filterTypes[(string) $column['filter_type']] ?? (string) $column['filter_type']); ?></td>
                    <td><?php echo !empty($column['is_link']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo !empty($column['is_report']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo !empty($column['is_table']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo !empty($column['is_export']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo !empty($column['at_a_glance']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo !empty($column['show_on_web']) ? 'Yes' : 'No'; ?></td>
                    <td class="table-actions">
                      <button type="button" class="icon-action" data-open-modal="<?php echo hub_h($modalId); ?>" title="Edit column" aria-label="Edit column"><i class="fa-solid fa-pencil" aria-hidden="true"></i></button>
                      <?php if (!empty($column['at_a_glance'])): ?>
                        <a class="icon-action" href="/admin/report-colours.php?<?php echo hub_h(http_build_query(['report' => $column['report_key'], 'column' => $column['column_key']])); ?>" title="Manage At a Glance colours" aria-label="Manage At a Glance colours"><i class="fa-solid fa-palette" aria-hidden="true"></i></a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php
    $modalColumns = $columns;
    $modalColumns[] = [
      'id' => 0,
      'report_key' => 'shipping',
      'column_key' => '',
      'source_field' => '',
      'data_field' => '',
      'heading' => '',
      'display_type' => 'text',
      'filter_type' => 'search',
      'show_on_web' => 1,
      'at_a_glance' => 0,
      'is_link' => 0,
      'is_report' => 0,
      'is_table' => 1,
      'is_export' => 1,
      'sort' => 100,
    ];
  ?>
  <?php foreach ($modalColumns as $column): ?>
    <?php
      $columnId = (int) ($column['id'] ?? 0);
      $modalId = $columnId > 0 ? 'reportColumnModal' . $columnId : 'reportColumnAddModal';
    ?>
    <div class="modal admin-entity-modal" id="<?php echo hub_h($modalId); ?>" aria-hidden="true">
      <div class="modal-content">
        <div class="modal-header">
          <h2><?php echo $columnId > 0 ? 'Edit Report Column' : 'Add Report Column'; ?></h2>
          <button type="button" class="close-btn but2" data-close-modal="<?php echo hub_h($modalId); ?>" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="/admin/report-columns.php">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <input type="hidden" name="action" value="<?php echo $columnId > 0 ? 'update_report_column' : 'add_report_column'; ?>">
          <input type="hidden" name="column_id" value="<?php echo $columnId; ?>">
          <div>
            <label for="report_key_<?php echo $columnId; ?>">Report</label>
            <select id="report_key_<?php echo $columnId; ?>" name="report_key">
              <?php foreach ($reportOptions as $reportKey => $reportLabel): ?>
                <option value="<?php echo hub_h($reportKey); ?>" <?php echo (string) ($column['report_key'] ?? '') === $reportKey ? 'selected' : ''; ?>><?php echo hub_h($reportLabel); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="source_field_<?php echo $columnId; ?>">Imported Data Field</label>
            <select id="source_field_<?php echo $columnId; ?>" name="source_field" data-imported-field data-current-field="<?php echo hub_h((string) ($column['source_field'] ?? '')); ?>" required></select>
            <input name="column_key" type="hidden" value="<?php echo hub_h((string) ($column['column_key'] ?? '')); ?>" data-column-key>
            <input name="data_field" type="hidden" value="<?php echo hub_h((string) ($column['data_field'] ?? '')); ?>" data-data-field>
            <p class="muted">Selected from fields found in the latest imported data. Internal field keys are managed automatically.</p>
          </div>
          <div>
            <label for="heading_<?php echo $columnId; ?>">Heading</label>
            <input id="heading_<?php echo $columnId; ?>" name="heading" type="text" value="<?php echo hub_h((string) ($column['heading'] ?? '')); ?>" required>
          </div>
          <div>
            <label for="display_type_<?php echo $columnId; ?>">Display Type</label>
            <select id="display_type_<?php echo $columnId; ?>" name="display_type">
              <?php foreach ($displayTypes as $typeKey => $typeLabel): ?>
                <option value="<?php echo hub_h($typeKey); ?>" <?php echo (string) ($column['display_type'] ?? '') === $typeKey ? 'selected' : ''; ?>><?php echo hub_h($typeLabel); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="filter_type_<?php echo $columnId; ?>">Filter Type</label>
            <select id="filter_type_<?php echo $columnId; ?>" name="filter_type">
              <?php foreach ($filterTypes as $typeKey => $typeLabel): ?>
                <option value="<?php echo hub_h($typeKey); ?>" <?php echo (string) ($column['filter_type'] ?? '') === $typeKey ? 'selected' : ''; ?>><?php echo hub_h($typeLabel); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="sort_<?php echo $columnId; ?>">Sort</label>
            <input id="sort_<?php echo $columnId; ?>" name="sort" type="number" value="<?php echo (int) ($column['sort'] ?? 100); ?>">
          </div>
          <div class="modal-checkbox-row dashboard-button-visibility">
            <label><input type="checkbox" name="show_on_web" value="1" <?php echo !empty($column['show_on_web']) ? 'checked' : ''; ?>> <span>Live</span></label>
            <label><input type="checkbox" name="at_a_glance" value="1" <?php echo !empty($column['at_a_glance']) ? 'checked' : ''; ?>> <span>At a Glance</span></label>
            <label><input type="checkbox" name="is_link" value="1" <?php echo !empty($column['is_link']) ? 'checked' : ''; ?>> <span>Link</span></label>
            <label><input type="checkbox" name="is_report" value="1" <?php echo !empty($column['is_report']) ? 'checked' : ''; ?>> <span>Report</span></label>
            <label><input type="checkbox" name="is_table" value="1" <?php echo !empty($column['is_table']) ? 'checked' : ''; ?>> <span>Table</span></label>
            <label><input type="checkbox" name="is_export" value="1" <?php echo !empty($column['is_export']) ? 'checked' : ''; ?>> <span>Export</span></label>
          </div>
          <div class="links admin-page-actions">
            <button type="submit"><?php echo $columnId > 0 ? 'Save Column' : 'Add Column'; ?></button>
            <?php if ($columnId > 0): ?>
              <button type="submit" name="action" value="archive_report_column">Remove Column</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <script>
    (function () {
      var importedFields = <?php echo json_encode($availableFields, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

      function fieldHeading(field) {
        return field.replace(/[_-]+/g, ' ').replace(/([a-z0-9])([A-Z])/g, '$1 $2').replace(/\b\w/g, function (letter) {
          return letter.toUpperCase();
        });
      }

      function syncFieldMapping(form, updateHeading) {
        var fieldSelect = form.querySelector('[data-imported-field]');
        if (!fieldSelect || !fieldSelect.value) return;
        var columnKey = form.querySelector('[data-column-key]');
        var dataField = form.querySelector('[data-data-field]');
        if (columnKey) columnKey.value = fieldSelect.value;
        if (dataField) dataField.value = fieldSelect.value;
        var heading = form.querySelector('input[name="heading"]');
        if (updateHeading && heading && heading.value.trim() === '') heading.value = fieldHeading(fieldSelect.value);
      }

      function populateImportedFields(form, preserveCurrent) {
        var reportSelect = form.querySelector('select[name="report_key"]');
        var fieldSelect = form.querySelector('[data-imported-field]');
        if (!reportSelect || !fieldSelect) return;
        var current = preserveCurrent ? fieldSelect.getAttribute('data-current-field') || fieldSelect.value : '';
        var fields = importedFields[reportSelect.value] || [];
        fieldSelect.innerHTML = '';
        fields.forEach(function (field) {
          var option = document.createElement('option');
          option.value = field;
          option.textContent = field;
          if (field === current) option.selected = true;
          fieldSelect.appendChild(option);
        });
        if (!fields.length) {
          var empty = document.createElement('option');
          empty.value = '';
          empty.textContent = 'No imported fields found';
          empty.disabled = true;
          empty.selected = true;
          fieldSelect.appendChild(empty);
          return;
        }
        if (!current || fieldSelect.value !== current) syncFieldMapping(form, true);
      }

      document.querySelectorAll('.admin-entity-modal form').forEach(function (form) {
        populateImportedFields(form, true);
        var reportSelect = form.querySelector('select[name="report_key"]');
        var fieldSelect = form.querySelector('[data-imported-field]');
        if (reportSelect) reportSelect.addEventListener('change', function () {
          populateImportedFields(form, false);
          syncFieldMapping(form, true);
        });
        if (fieldSelect) fieldSelect.addEventListener('change', function () {
          syncFieldMapping(form, true);
        });
      });

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
    })();
  </script>
</body>
</html>
