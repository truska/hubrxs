<?php
require_once __DIR__ . '/../includes/app/imports/remote_sources.php';
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_admin();

global $pdo, $DB_OK;

$type = (string) ($_GET['type'] ?? $_POST['type'] ?? 'so');
if (!in_array($type, ['so', 'inventory'], true)) {
  $type = 'so';
}

$configs = [
  'so' => [
    'label' => 'SO Portal Lines',
    'import_name' => 'so_portal_lines',
    'source_field' => 'CustomerName',
    'live_table' => 'hub_so_live',
    'processed_table' => 'hub_so_processed',
    'value_column' => 'source_customer_value',
    'field_column' => 'source_customer_field',
  ],
  'inventory' => [
    'label' => 'Inventory',
    'import_name' => 'portal_inventory',
    'source_field' => 'description_2',
    'live_table' => 'hub_inventory_live',
    'processed_table' => 'hub_inventory_processed',
    'value_column' => 'source_customer_value',
    'field_column' => null,
  ],
];
$config = $configs[$type];
$errors = [];

function hub_map_customers_table_has_column(string $table, string $column): bool {
  global $pdo;
  try {
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE :column');
    $stmt->execute([':column' => $column]);
    return (bool) $stmt->fetchColumn();
  } catch (PDOException $e) {
    return false;
  }
}

function hub_map_customers_safe_table(string $table): string {
  return '`' . str_replace('`', '', $table) . '`';
}

function hub_map_customers_update_import_rows(array $config, string $sourceValue, int $customerId): int {
  global $pdo;

  $updatedRows = 0;
  foreach (['live_table', 'processed_table'] as $tableKey) {
    $table = $config[$tableKey];
    if (!hub_table_exists($table) || !hub_map_customers_table_has_column($table, 'customer_id')) {
      continue;
    }

    $sql = 'UPDATE ' . hub_map_customers_safe_table($table) . '
            SET customer_id = :customer_id, modified = NOW()
            WHERE ' . $config['value_column'] . ' = :source_value';
    $params = [
      ':customer_id' => $customerId,
      ':source_value' => $sourceValue,
    ];
    if ($config['field_column'] && hub_map_customers_table_has_column($table, $config['field_column'])) {
      $sql .= ' AND ' . $config['field_column'] . ' = :source_field';
      $params[':source_field'] = $config['source_field'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $updatedRows += $stmt->rowCount();
  }

  return $updatedRows;
}

function hub_map_customers_complete_mapping_action(array $config, string $sourceNorm): void {
  global $pdo;

  if (!hub_table_exists('hub_admin_action')) {
    return;
  }

  $actionKey = 'map_customer:' . $config['import_name'] . ':' . $config['source_field'] . ':' . $sourceNorm;
  $stmtAction = $pdo->prepare('UPDATE hub_admin_action SET status = :status, modified = NOW() WHERE action_key = :action_key AND action_type = :action_type');
  $stmtAction->execute([
    ':status' => 'complete',
    ':action_key' => $actionKey,
    ':action_type' => 'map_customer',
  ]);
}

function hub_map_customers_customer_code(string $sourceValue): string {
  global $pdo;

  $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $sourceValue);
  $base = is_string($ascii) && $ascii !== '' ? $ascii : $sourceValue;
  $base = strtoupper(trim(preg_replace('/[^A-Z0-9]+/i', '_', $base) ?: ''));
  $base = trim(preg_replace('/_+/', '_', $base) ?: $base, '_');
  if ($base === '') {
    $base = 'CUSTOMER';
  }
  $base = substr($base, 0, 80);
  $code = $base;
  $suffix = 2;

  while (hub_table_exists('hub_customer')) {
    $stmt = $pdo->prepare('SELECT id FROM hub_customer WHERE code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    if (!(int) $stmt->fetchColumn()) {
      return $code;
    }
    $tail = '_' . $suffix++;
    $code = substr($base, 0, 100 - strlen($tail)) . $tail;
  }

  return substr($code, 0, 100);
}

function hub_map_customers_create_customer(array $config, string $sourceValue): int {
  global $pdo;

  if (!hub_table_exists('hub_customer')) {
    throw new RuntimeException('Customer table is not available.');
  }

  $name = mb_substr(trim($sourceValue), 0, 200);
  if ($name === '') {
    throw new RuntimeException('Imported customer value is blank.');
  }

  $stmtExisting = $pdo->prepare('SELECT id FROM hub_customer WHERE name = :name OR code = :name LIMIT 1');
  $stmtExisting->execute([':name' => $name]);
  if ((int) $stmtExisting->fetchColumn() > 0) {
    throw new RuntimeException('A customer with this name or code already exists. Select the existing customer instead.');
  }

  $code = hub_map_customers_customer_code($name);
  $stmt = $pdo->prepare('INSERT INTO hub_customer (name, code, notes, archived, created, modified) VALUES (:name, :code, :notes, 0, NOW(), NOW())');
  $stmt->execute([
    ':name' => $name,
    ':code' => $code,
    ':notes' => 'Created from imported customer value. Details need review and completion.',
  ]);
  $customerId = (int) $pdo->lastInsertId();

  hub_import_admin_action_open(
    'complete_customer:' . $customerId,
    'complete_customer',
    'customer',
    $customerId,
    'Complete customer details: ' . $name,
    'Customer was created from imported value "' . $sourceValue . '" in ' . $config['import_name'] . '. Please complete contact details, user assignment, and any required customer information.',
    $config['import_name']
  );

  return $customerId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_mappings') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $errors[] = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO)) {
    $errors[] = 'Database not available.';
  } else {
    $sourceValues = is_array($_POST['source_value'] ?? null) ? $_POST['source_value'] : [];
    $customerIds = is_array($_POST['customer_id'] ?? null) ? $_POST['customer_id'] : [];
    $saveIdx = array_key_exists('save_idx', $_POST) ? (string) $_POST['save_idx'] : null;
    $createIdx = array_key_exists('create_idx', $_POST) ? (string) $_POST['create_idx'] : null;
    $onlyIdx = $createIdx ?? $saveIdx;
    $saved = 0;
    $created = 0;
    $updatedRows = 0;

    foreach ($sourceValues as $idx => $sourceValueRaw) {
      if ($onlyIdx !== null && (string) $idx !== $onlyIdx) {
        continue;
      }

      $sourceValue = trim((string) $sourceValueRaw);
      if ($sourceValue === '') {
        continue;
      }

      try {
        if ($createIdx !== null && (string) $idx === $createIdx) {
          $customerId = hub_map_customers_create_customer($config, $sourceValue);
          $created++;
        } else {
          $customerId = (int) ($customerIds[$idx] ?? 0);
          if ($customerId <= 0) {
            continue;
          }
        }

        $sourceNorm = hub_import_customer_source_norm($sourceValue);
        hub_import_customer_mapping_save(
          $config['import_name'],
          $config['source_field'],
          $sourceValue,
          $sourceNorm,
          $customerId,
          $created > 0 && $createIdx !== null && (string) $idx === $createIdx
            ? 'Created new customer and mapped manually in admin.'
            : 'Mapped manually in admin.'
        );
        $updatedRows += hub_map_customers_update_import_rows($config, $sourceValue, $customerId);
        hub_map_customers_complete_mapping_action($config, $sourceNorm);
        $saved++;
      } catch (Throwable $e) {
        $errors[] = 'Unable to map "' . $sourceValue . '": ' . $e->getMessage();
      }
    }

    if (!$errors) {
      if ($onlyIdx !== null && $saved === 0) {
        hub_flash('info', 'No mapping was saved. Select a customer, or use Create customer.');
      } else {
        $msg = 'Saved ' . $saved . ' mapping' . ($saved === 1 ? '' : 's') . ' and updated ' . number_format($updatedRows) . ' rows.';
        if ($created > 0) {
          $msg .= ' Created ' . $created . ' customer' . ($created === 1 ? '' : 's') . ' for completion.';
        }
        hub_flash('success', $msg);
      }
      hub_redirect('/admin/map-customers.php?type=' . rawurlencode($type));
    }
  }
}

$customers = [];
$unmapped = [];
if ($DB_OK && ($pdo instanceof PDO)) {
  if (hub_table_exists('hub_customer')) {
    $stmtCustomers = $pdo->query('SELECT id, name, code FROM hub_customer WHERE archived = 0 ORDER BY name ASC, id ASC');
    $customers = $stmtCustomers ? ($stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  }

  $table = $config['live_table'];
  if (hub_table_exists($table) && hub_map_customers_table_has_column($table, 'customer_id')) {
    $sql = 'SELECT ' . $config['value_column'] . ' AS source_value, COUNT(*) AS row_count
            FROM ' . hub_map_customers_safe_table($table) . '
            WHERE customer_id = 0
              AND ' . $config['value_column'] . ' IS NOT NULL
              AND TRIM(' . $config['value_column'] . ') <> \'\'';
    if ($config['field_column'] && hub_map_customers_table_has_column($table, $config['field_column'])) {
      $sql .= ' AND ' . $config['field_column'] . ' = :source_field';
    }
    $sql .= ' GROUP BY ' . $config['value_column'] . ' ORDER BY row_count DESC, source_value ASC';
    $stmtUnmapped = $pdo->prepare($sql);
    if ($config['field_column'] && hub_map_customers_table_has_column($table, $config['field_column'])) {
      $stmtUnmapped->execute([':source_field' => $config['source_field']]);
    } else {
      $stmtUnmapped->execute();
    }
    $unmapped = $stmtUnmapped->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

$messages = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Map Customers</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page map-customers-page">
  <?php echo hub_admin_header('admin'); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card mapping-controls">
        <div class="flex">
          <div>
            <p class="brand">Imports</p>
            <h1>Map Customers</h1>
            <p class="muted">Create customer mappings for imported rows that do not yet have a customer ID.</p>
          </div>
          <div class="links">
            <a href="/admin.php">Back to admin</a>
            <a href="/admin/customers.php">Customers</a>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endforeach; ?>

        <form class="mapping-filter" method="get" action="/admin/map-customers.php">
          <div>
            <label for="type">Import data</label>
            <select id="type" name="type" onchange="this.form.submit()">
              <option value="so" <?php echo $type === 'so' ? 'selected' : ''; ?>>SO Portal Lines</option>
              <option value="inventory" <?php echo $type === 'inventory' ? 'selected' : ''; ?>>Inventory</option>
            </select>
          </div>
          <div>
            <label>Source field</label>
            <input type="text" value="<?php echo hub_h($config['source_field']); ?>" readonly>
          </div>
          <div>
            <label>Unmapped values</label>
            <input type="text" value="<?php echo number_format(count($unmapped)); ?>" readonly>
          </div>
        </form>
      </div>

      <div class="card mapping-list-card">
        <div class="flex">
          <div>
            <p class="brand"><?php echo hub_h($config['label']); ?></p>
            <h2>Unmapped customer values</h2>
            <p class="muted">Select an existing customer, or create a new customer from the imported value.</p>
          </div>
        </div>

        <?php if (!$unmapped): ?>
          <div class="alert success">No unmapped customer values found for this import.</div>
        <?php else: ?>
          <form method="post" action="/admin/map-customers.php">
            <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
            <input type="hidden" name="action" value="save_mappings">
            <input type="hidden" name="type" value="<?php echo hub_h($type); ?>">
            <div class="import-data-table-wrap">
              <table class="table mapping-table import-data-table">
                <thead>
                  <tr>
                    <th>Imported value</th>
                    <th>Rows</th>
                    <th>Existing customer</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($unmapped as $idx => $row): ?>
                    <tr>
                      <td>
                        <strong><?php echo hub_h((string) $row['source_value']); ?></strong>
                        <input type="hidden" name="source_value[<?php echo (int) $idx; ?>]" value="<?php echo hub_h((string) $row['source_value']); ?>">
                      </td>
                      <td class="mapping-count"><?php echo number_format((int) $row['row_count']); ?></td>
                      <td>
                        <select name="customer_id[<?php echo (int) $idx; ?>]">
                          <option value="0"><?php echo $customers ? 'Select customer' : 'No existing customers'; ?></option>
                          <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo (int) $customer['id']; ?>">
                              <?php echo hub_h(trim((string) $customer['name'] . ' [' . (string) $customer['code'] . ']')); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td>
                        <div class="mapping-actions">
                          <button type="submit" name="save_idx" value="<?php echo (int) $idx; ?>">Save row</button>
                          <button type="submit" name="create_idx" value="<?php echo (int) $idx; ?>">Create customer</button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="links" style="justify-content:flex-start; margin-top:12px;">
              <button type="submit">Save selected mappings</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>