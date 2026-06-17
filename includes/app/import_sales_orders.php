<?php
require_once __DIR__ . '/auth.php';

function hub_parse_date(?string $value): ?string {
  $trimmed = trim((string) $value);
  if ($trimmed === '') {
    return null;
  }
  try {
    $dt = new DateTime($trimmed);
    return $dt->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

function hub_customer_id_for_code(string $code): ?int {
  global $pdo, $DB_OK;

  static $cache = [];
  $code = trim($code);
  if ($code === '') {
    return null;
  }
  if (isset($cache[$code])) {
    return $cache[$code];
  }

  if (!$DB_OK || !($pdo instanceof PDO)) {
    $cache[$code] = null;
    return null;
  }

  try {
    $stmt = $pdo->prepare('SELECT id FROM hub_customer WHERE code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $id = $stmt->fetchColumn();
    $cache[$code] = $id ? (int) $id : null;
    return $cache[$code];
  } catch (PDOException $e) {
    $cache[$code] = null;
    return null;
  }
}

function hub_import_sales_order_file(string $filePath, string $originalName = null, ?int $uploadedBy = null): array {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO)) {
    return ['ok' => false, 'message' => 'Database not available.', 'rows' => 0];
  }
  if (!is_readable($filePath)) {
    return ['ok' => false, 'message' => 'File not readable: ' . $filePath, 'rows' => 0];
  }

  $jsonText = file_get_contents($filePath);
  $sha = hash('sha256', (string) $jsonText);
  $originalName = $originalName ?: basename($filePath);

  $data = json_decode((string) $jsonText, true);
  if (!is_array($data) || !isset($data['value']) || !is_array($data['value'])) {
    return ['ok' => false, 'message' => 'Unexpected JSON format (missing value array).', 'rows' => 0];
  }

  $rows = $data['value'];
  $rowCount = count($rows);

  // Create import file record.
  $stmtFile = $pdo->prepare(
    'INSERT INTO hub_import_file (dataset, original_name, stored_name, sha256, status, row_count, processed_count, error_text, uploaded_by, created)
     VALUES (:dataset, :original_name, :stored_name, :sha256, :status, :row_count, 0, NULL, :uploaded_by, NOW())'
  );
  $stmtFile->execute([
    ':dataset' => 'SalesOrder',
    ':original_name' => $originalName,
    ':stored_name' => basename($filePath),
    ':sha256' => $sha,
    ':status' => 'processing',
    ':row_count' => $rowCount,
    ':uploaded_by' => $uploadedBy,
  ]);
  $importId = (int) $pdo->lastInsertId();

  $insertRow = $pdo->prepare(
    'INSERT INTO hub_import_row
      (import_id, customer_code, order_type, order_nbr, branch_id, currency, order_total, order_before_tax, tax_total, ordered_qty, date, requested_on, sched_shipment, shipment_date, description, project, account_name, last_modified_on, raw_json, created)
     VALUES
      (:import_id, :customer_code, :order_type, :order_nbr, :branch_id, :currency, :order_total, :order_before_tax, :tax_total, :ordered_qty, :date, :requested_on, :sched_shipment, :shipment_date, :description, :project, :account_name, :last_modified_on, :raw_json, NOW())'
  );

  $upsert = $pdo->prepare(
    'INSERT INTO hub_sales_order
      (import_id, customer_id, customer_code, order_type, order_nbr, branch_id, behavior, status, currency, order_total, order_before_tax, tax_total, ordered_qty, requested_on, sched_shipment, date, shipment_date, description, project, account_name, owner, created_by, created_on, last_modified_by, last_modified_on, account_id, location_id, contact_id)
     VALUES
      (:import_id, :customer_id, :customer_code, :order_type, :order_nbr, :branch_id, :behavior, :status, :currency, :order_total, :order_before_tax, :tax_total, :ordered_qty, :requested_on, :sched_shipment, :date, :shipment_date, :description, :project, :account_name, :owner, :created_by, :created_on, :last_modified_by, :last_modified_on, :account_id, :location_id, :contact_id)
     ON DUPLICATE KEY UPDATE
      import_id = VALUES(import_id),
      customer_id = VALUES(customer_id),
      customer_code = VALUES(customer_code),
      behavior = VALUES(behavior),
      status = VALUES(status),
      currency = VALUES(currency),
      order_total = VALUES(order_total),
      order_before_tax = VALUES(order_before_tax),
      tax_total = VALUES(tax_total),
      ordered_qty = VALUES(ordered_qty),
      requested_on = VALUES(requested_on),
      sched_shipment = VALUES(sched_shipment),
      date = VALUES(date),
      shipment_date = VALUES(shipment_date),
      description = VALUES(description),
      project = VALUES(project),
      account_name = VALUES(account_name),
      owner = VALUES(owner),
      created_by = VALUES(created_by),
      created_on = VALUES(created_on),
      last_modified_by = VALUES(last_modified_by),
      last_modified_on = VALUES(last_modified_on),
      account_id = VALUES(account_id),
      location_id = VALUES(location_id),
      contact_id = VALUES(contact_id)'
  );

  $processed = 0;

  try {
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $customerCode = trim((string) ($row['Customer'] ?? ''));
      $orderType = (string) ($row['OrderType'] ?? '');
      $orderNbr = (string) ($row['OrderNbr'] ?? '');
      $branchId = trim((string) ($row['BranchID'] ?? $row['Branch'] ?? ''));
      $currency = (string) ($row['Currency'] ?? '');
      $orderTotal = isset($row['OrderTotal']) ? (float) $row['OrderTotal'] : null;
      $orderBeforeTax = isset($row['OrderbeforeTax']) ? (float) $row['OrderbeforeTax'] : null;
      $taxTotal = isset($row['TaxTotal']) ? (float) $row['TaxTotal'] : null;
      $orderedQty = isset($row['OrderedQty']) ? (float) $row['OrderedQty'] : null;

      $insertRow->execute([
        ':import_id' => $importId,
        ':customer_code' => $customerCode,
        ':order_type' => $orderType,
        ':order_nbr' => $orderNbr,
        ':branch_id' => $branchId,
        ':currency' => $currency,
        ':order_total' => $orderTotal,
        ':order_before_tax' => $orderBeforeTax,
        ':tax_total' => $taxTotal,
        ':ordered_qty' => $orderedQty,
        ':date' => hub_parse_date($row['Date'] ?? null),
        ':requested_on' => hub_parse_date($row['RequestedOn'] ?? null),
        ':sched_shipment' => hub_parse_date($row['SchedShipment'] ?? null),
        ':shipment_date' => hub_parse_date($row['ShipmentDate'] ?? null),
        ':description' => (string) ($row['Description'] ?? ''),
        ':project' => (string) ($row['Project'] ?? ''),
        ':account_name' => (string) ($row['AccountName'] ?? ''),
        ':last_modified_on' => hub_parse_date($row['LastModifiedOn'] ?? null),
        ':raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE),
      ]);

      $customerId = hub_customer_id_for_code($customerCode);

      $upsert->execute([
        ':import_id' => $importId,
        ':customer_id' => $customerId,
        ':customer_code' => $customerCode,
        ':order_type' => $orderType,
        ':order_nbr' => $orderNbr,
        ':branch_id' => $branchId,
        ':behavior' => (string) ($row['Behavior'] ?? ''),
        ':status' => (string) ($row['Status'] ?? ''),
        ':currency' => $currency,
        ':order_total' => $orderTotal,
        ':order_before_tax' => $orderBeforeTax,
        ':tax_total' => $taxTotal,
        ':ordered_qty' => $orderedQty,
        ':requested_on' => hub_parse_date($row['RequestedOn'] ?? null),
        ':sched_shipment' => hub_parse_date($row['SchedShipment'] ?? null),
        ':date' => hub_parse_date($row['Date'] ?? null),
        ':shipment_date' => hub_parse_date($row['ShipmentDate'] ?? null),
        ':description' => (string) ($row['Description'] ?? ''),
        ':project' => (string) ($row['Project'] ?? ''),
        ':account_name' => (string) ($row['AccountName'] ?? ''),
        ':owner' => (string) ($row['Owner'] ?? ''),
        ':created_by' => (string) ($row['CreatedBy'] ?? ''),
        ':created_on' => hub_parse_date($row['CreatedOn'] ?? null),
        ':last_modified_by' => (string) ($row['LastModifiedBy'] ?? ''),
        ':last_modified_on' => hub_parse_date($row['LastModifiedOn'] ?? null),
        ':account_id' => (string) ($row['AccountID'] ?? ''),
        ':location_id' => (string) ($row['LocationID'] ?? ''),
        ':contact_id' => (string) ($row['ContactID'] ?? ''),
      ]);

      $processed++;
    }

    $update = $pdo->prepare(
      'UPDATE hub_import_file SET status = :status, processed_count = :processed, row_count = :row_count, processed_at = NOW() WHERE id = :id'
    );
    $update->execute([
      ':status' => 'complete',
      ':processed' => $processed,
      ':row_count' => $rowCount,
      ':id' => $importId,
    ]);
  } catch (Throwable $e) {
    $fail = $pdo->prepare('UPDATE hub_import_file SET status = :status, error_text = :error, processed_count = :processed, row_count = :row_count, processed_at = NOW() WHERE id = :id');
    $fail->execute([
      ':status' => 'failed',
      ':error' => $e->getMessage(),
      ':processed' => $processed,
      ':row_count' => $rowCount,
      ':id' => $importId,
    ]);
    return ['ok' => false, 'message' => 'Import failed: ' . $e->getMessage(), 'rows' => $processed];
  }

  return [
    'ok' => true,
    'message' => 'Imported ' . $processed . ' of ' . $rowCount . ' rows.',
    'rows' => $processed,
    'import_id' => $importId,
    'sha256' => $sha,
  ];
}
