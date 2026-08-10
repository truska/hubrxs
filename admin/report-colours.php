<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/report_columns.php';
hub_require_super_admin();

global $pdo, $DB_OK;

$reportKey = trim((string) ($_GET['report'] ?? $_POST['report_key'] ?? ''));
$columnKey = trim((string) ($_GET['column'] ?? $_POST['column_key'] ?? ''));
$error = null;
$messages = hub_flash_messages();
$column = null;
$palette = hub_report_secondary_colour_palette();

if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_report_column')) {
  $stmtColumn = $pdo->prepare(
    'SELECT * FROM hub_report_column
     WHERE report_key = :report_key AND column_key = :column_key
       AND at_a_glance = 1 AND archived = 0
     LIMIT 1'
  );
  $stmtColumn->execute([':report_key' => $reportKey, ':column_key' => $columnKey]);
  $column = $stmtColumn->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hub_admin_report_colour_value(array $row, string $key): string {
  foreach ($row as $rowKey => $value) {
    if (mb_strtolower((string) $rowKey) === mb_strtolower($key)) {
      return trim((string) $value);
    }
  }
  return '';
}

function hub_admin_report_detect_values(array $column): array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    return [];
  }
  $reportKey = (string) ($column['report_key'] ?? '');
  $dataField = (string) ($column['data_field'] ?? '');
  $sourceField = (string) ($column['source_field'] ?? $dataField);
  $values = [];

  try {
    if ($reportKey === 'shipping' && hub_table_exists('hub_so_live')) {
      if (hub_table_column_exists('hub_so_live', $dataField)) {
        $safeField = str_replace('`', '', $dataField);
        $rows = $pdo->query(
          'SELECT DISTINCT `' . $safeField . '` AS report_value FROM hub_so_live
           WHERE archived = 0 AND `' . $safeField . '` IS NOT NULL AND `' . $safeField . '` <> ""
           ORDER BY `' . $safeField . '` ASC LIMIT 500'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $value) {
          $value = trim((string) $value);
          if ($value !== '') $values[hub_report_value_key($value)] = $value;
        }
      } elseif (hub_table_column_exists('hub_so_live', 'raw_json')) {
        foreach ($pdo->query('SELECT raw_json FROM hub_so_live WHERE archived = 0 AND raw_json IS NOT NULL LIMIT 5000')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
          $row = json_decode((string) $json, true);
          $value = is_array($row) ? hub_admin_report_colour_value($row, $sourceField) : '';
          if ($value !== '') $values[hub_report_value_key($value)] = $value;
        }
      }
    } elseif ($reportKey === 'inventory' && hub_table_exists('hub_inventory_live')) {
      $jsonColumn = hub_table_column_exists('hub_inventory_live', 'json_data') ? 'json_data' : 'raw_json';
      if (hub_table_column_exists('hub_inventory_live', $jsonColumn)) {
        foreach ($pdo->query('SELECT `' . $jsonColumn . '` FROM hub_inventory_live WHERE archived = 0 AND `' . $jsonColumn . '` IS NOT NULL LIMIT 5000')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
          $row = json_decode((string) $json, true);
          $value = is_array($row) ? hub_admin_report_colour_value($row, $sourceField) : '';
          if ($value === '' && is_array($row)) $value = hub_admin_report_colour_value($row, $dataField);
          if ($value !== '') $values[hub_report_value_key($value)] = $value;
        }
      }
    }
  } catch (PDOException $e) {
    return $values;
  }
  natcasesort($values);
  return $values;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!$column || !$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_report_value_colour')) {
    $error = 'At a Glance colour settings are not available.';
  } else {
    $action = (string) ($_POST['action'] ?? '');
    $valueKey = trim((string) ($_POST['value_key'] ?? ''));
    if ($valueKey === '') {
      $error = 'A report value is required.';
    } elseif ($action === 'remove_colour') {
      $stmt = $pdo->prepare(
        'UPDATE hub_report_value_colour SET archived = 1, show_on_web = 0, modified = NOW()
         WHERE report_key = :report_key AND column_key = :column_key AND value_key = :value_key LIMIT 1'
      );
      $stmt->execute([':report_key' => $reportKey, ':column_key' => $columnKey, ':value_key' => $valueKey]);
      hub_log_user_action([
        'action_key' => 'report_value_colour_removed',
        'action_title' => 'At a Glance colour removed',
        'table_name' => 'hub_report_value_colour',
        'record_id' => $reportKey . ':' . $columnKey . ':' . $valueKey,
        'details' => ['report' => $reportKey, 'column' => $columnKey, 'value' => $valueKey, 'fallback_enabled' => true],
      ]);
      hub_flash('success', 'Custom colour removed; the fallback palette will be used.');
      hub_redirect('/admin/report-colours.php?' . http_build_query(['report' => $reportKey, 'column' => $columnKey]));
    } elseif ($action === 'save_colour') {
      $paletteColour = strtolower(trim((string) ($_POST['palette_colour'] ?? '')));
      $customColour = strtolower(trim((string) ($_POST['custom_colour'] ?? '')));
      $colour = $customColour !== '' ? $customColour : $paletteColour;
      if ($customColour === '' && !array_key_exists($paletteColour, $palette)) {
        $error = 'Select a palette colour or enter a custom colour.';
      } elseif (!preg_match('/^#[0-9a-f]{6}$/i', $colour)) {
        $error = 'Custom colour must be a six-digit hex value such as #1f9acb.';
      } else {
        $displayLabel = trim((string) ($_POST['display_label'] ?? '')) ?: $valueKey;
        $sort = (int) ($_POST['sort'] ?? 100);
        $showWhenZero = isset($_POST['show_when_zero']) ? 1 : 0;
        $stmt = $pdo->prepare(
          'INSERT INTO hub_report_value_colour
            (report_key, column_key, value_key, display_label, colour, sort, show_when_zero, show_on_web, archived, created, modified)
           VALUES
            (:report_key, :column_key, :value_key, :display_label, :colour, :sort, :show_when_zero, 1, 0, NOW(), NOW())
           ON DUPLICATE KEY UPDATE display_label = VALUES(display_label), colour = VALUES(colour),
             sort = VALUES(sort), show_when_zero = VALUES(show_when_zero), show_on_web = 1, archived = 0, modified = NOW()'
        );
        $stmt->execute([
          ':report_key' => $reportKey,
          ':column_key' => $columnKey,
          ':value_key' => $valueKey,
          ':display_label' => $displayLabel,
          ':colour' => $colour,
          ':sort' => $sort,
          ':show_when_zero' => $showWhenZero,
        ]);
        hub_log_user_action([
          'action_key' => 'report_value_colour_saved',
          'action_title' => 'At a Glance colour saved',
          'table_name' => 'hub_report_value_colour',
          'record_id' => $reportKey . ':' . $columnKey . ':' . $valueKey,
          'details' => ['report' => $reportKey, 'column' => $columnKey, 'value' => $valueKey, 'colour' => $colour, 'custom_override' => $customColour !== ''],
        ]);
        hub_flash('success', 'At a Glance colour saved.');
        hub_redirect('/admin/report-colours.php?' . http_build_query(['report' => $reportKey, 'column' => $columnKey]));
      }
    }
  }
}

$saved = [];
if ($column && $DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_report_value_colour')) {
  $stmtSaved = $pdo->prepare(
    'SELECT * FROM hub_report_value_colour
     WHERE report_key = :report_key AND column_key = :column_key AND archived = 0
     ORDER BY sort ASC, display_label ASC'
  );
  $stmtSaved->execute([':report_key' => $reportKey, ':column_key' => $columnKey]);
  foreach ($stmtSaved->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $saved[hub_report_value_key((string) $row['value_key'])] = $row;
  }
}
$detected = $column ? hub_admin_report_detect_values($column) : [];
$values = $detected;
foreach ($saved as $normalised => $row) {
  if (!isset($values[$normalised])) $values[$normalised] = (string) $row['value_key'];
}
natcasesort($values);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | At a Glance Colours</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
  <?php echo hub_admin_header('super'); ?>
  <div class="stack"><div class="wrap">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand">Reports</p>
          <h1>At a Glance Colours</h1>
          <p class="muted"><?php echo $column ? hub_h((string) $column['heading']) . ' · ' . hub_h(ucfirst($reportKey)) : 'At a Glance column not found'; ?></p>
        </div>
        <div class="links"><a href="/admin/report-columns.php">Back to Report Columns</a></div>
      </div>
      <?php foreach ($messages as $message): ?><div class="alert <?php echo hub_h($message['type']); ?>"><?php echo hub_h($message['message']); ?></div><?php endforeach; ?>
      <?php if ($error): ?><div class="alert error"><?php echo hub_h($error); ?></div><?php endif; ?>
      <div class="alert info">Palette colours are temporary until the client supplies its official secondary palette. A custom colour overrides the palette selection.</div>
    </div>

    <div class="card">
      <?php if (!$column): ?>
        <div class="alert error">This At a Glance column is not available.</div>
      <?php elseif (empty($values)): ?>
        <div class="alert info">No report values have been detected yet.</div>
      <?php else: ?>
        <div class="import-data-table-wrap">
          <table class="table import-data-table">
            <thead><tr><th>Report value</th><th>Display label</th><th>Palette colour</th><th>Custom colour</th><th>Sort</th><th>Show at zero</th><th>Effective</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($values as $normalised => $value): ?>
              <?php
                $config = $saved[$normalised] ?? null;
                $savedColour = strtolower(trim((string) ($config['colour'] ?? '')));
                $isPaletteColour = isset($palette[$savedColour]);
                $fallback = hub_report_fallback_colour($normalised);
                $effective = $savedColour !== '' ? $savedColour : $fallback;
                $formId = 'colour-form-' . substr(hash('sha256', $reportKey . '|' . $columnKey . '|' . $value), 0, 12);
              ?>
              <tr>
                <td><strong><?php echo hub_h($value); ?></strong><?php if (!$config): ?><div class="muted">Automatic fallback</div><?php endif; ?></td>
                <td><input form="<?php echo $formId; ?>" name="display_label" value="<?php echo hub_h((string) ($config['display_label'] ?? $value)); ?>"></td>
                <td>
                  <select form="<?php echo $formId; ?>" name="palette_colour">
                    <?php foreach ($palette as $hex => $name): ?><option value="<?php echo hub_h($hex); ?>" <?php echo (($isPaletteColour && $savedColour === $hex) || (!$config && $fallback === $hex)) ? 'selected' : ''; ?>><?php echo hub_h($name . ' · ' . $hex); ?></option><?php endforeach; ?>
                  </select>
                </td>
                <td><input form="<?php echo $formId; ?>" name="custom_colour" value="<?php echo $isPaletteColour ? '' : hub_h($savedColour); ?>" placeholder="#123abc" pattern="#[0-9A-Fa-f]{6}"></td>
                <td><input form="<?php echo $formId; ?>" name="sort" type="number" value="<?php echo (int) ($config['sort'] ?? 100); ?>" style="width:80px;"></td>
                <td><input form="<?php echo $formId; ?>" name="show_when_zero" type="checkbox" value="1" <?php echo !$config || !empty($config['show_when_zero']) ? 'checked' : ''; ?>></td>
                <td><span class="badge" style="background:<?php echo hub_h($effective); ?>;color:#fff;"><?php echo hub_h($effective); ?></span></td>
                <td class="table-actions">
                  <form id="<?php echo $formId; ?>" method="post" action="/admin/report-colours.php">
                    <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                    <input type="hidden" name="report_key" value="<?php echo hub_h($reportKey); ?>">
                    <input type="hidden" name="column_key" value="<?php echo hub_h($columnKey); ?>">
                    <input type="hidden" name="value_key" value="<?php echo hub_h($value); ?>">
                    <button type="submit" name="action" value="save_colour">Save</button>
                    <?php if ($config): ?><button type="submit" name="action" value="remove_colour">Use fallback</button><?php endif; ?>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div></div>
</body>
</html>
