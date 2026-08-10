<?php

function hub_portal_inventory_import_name(): string {
  return 'portal_inventory';
}

function hub_portal_inventory_source_field(): string {
  return 'description_2';
}

function hub_portal_inventory_customer_id_for_value(string $sourceValue): int {
  global $pdo;

  $sourceValue = trim($sourceValue);
  if ($sourceValue === '' || !hub_table_exists('hub_customer_mapping')) {
    return 0;
  }

  $importName = hub_portal_inventory_import_name();
  $sourceField = hub_portal_inventory_source_field();
  $sourceNorm = hub_import_customer_source_norm($sourceValue);

  $stmt = $pdo->prepare(
    'SELECT customer_id
     FROM hub_customer_mapping
     WHERE import_name = :import_name
       AND source_field = :source_field
       AND source_value_norm = :source_value_norm
       AND archived = 0
     LIMIT 1'
  );
  $stmt->execute([
    ':import_name' => $importName,
    ':source_field' => $sourceField,
    ':source_value_norm' => $sourceNorm,
  ]);
  $customerId = (int) $stmt->fetchColumn();
  if ($customerId > 0) {
    return $customerId;
  }

  if (hub_table_exists('hub_customer')) {
    $stmtCustomer = $pdo->prepare(
      'SELECT id
       FROM hub_customer
       WHERE archived = 0
         AND (name = :source_value OR code = :source_value)
       LIMIT 1'
    );
    $stmtCustomer->execute([':source_value' => $sourceValue]);
    $matchedCustomerId = (int) $stmtCustomer->fetchColumn();
    if ($matchedCustomerId > 0) {
      $stmtInsertMatch = $pdo->prepare(
        'INSERT INTO hub_customer_mapping
          (import_name, source_field, source_value, source_value_norm, customer_id, notes, archived, created, modified)
         VALUES
          (:import_name, :source_field, :source_value, :source_value_norm, :customer_id, :notes, 0, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
          customer_id = VALUES(customer_id),
          source_value = VALUES(source_value),
          modified = NOW()'
      );
      $stmtInsertMatch->execute([
        ':import_name' => $importName,
        ':source_field' => $sourceField,
        ':source_value' => $sourceValue,
        ':source_value_norm' => $sourceNorm,
        ':customer_id' => $matchedCustomerId,
        ':notes' => 'Created automatically from exact Hub customer match.',
      ]);
      return $matchedCustomerId;
    }
  }

  $stmtInsert = $pdo->prepare(
    'INSERT INTO hub_customer_mapping
      (import_name, source_field, source_value, source_value_norm, customer_id, notes, archived, created, modified)
     VALUES
      (:import_name, :source_field, :source_value, :source_value_norm, 0, :notes, 0, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      source_value = VALUES(source_value),
      modified = NOW()'
  );
  $stmtInsert->execute([
    ':import_name' => $importName,
    ':source_field' => $sourceField,
    ':source_value' => $sourceValue,
    ':source_value_norm' => $sourceNorm,
    ':notes' => 'Created automatically from Portal Inventory import. Allocate this source value to a Hub customer.',
  ]);

  hub_import_admin_action_open(
    'map_customer:' . $importName . ':' . $sourceField . ':' . $sourceNorm,
    'map_customer',
    'customer_mapping',
    null,
    'Map imported customer: ' . $sourceValue,
    'Portal Inventory value "' . $sourceValue . '" from field ' . $sourceField . ' has no customer mapping. Allocate it to a Hub customer.',
    $importName
  );

  return 0;
}

function hub_import_apply_portal_inventory_v2(int $sourceId, int $fetchId, array $rows): int {
  global $pdo;

  $sourceField = hub_portal_inventory_source_field();

  $pdo->prepare('DELETE FROM hub_inventory_raw WHERE source_id = :source_id')
    ->execute([':source_id' => $sourceId]);
  $pdo->prepare('DELETE FROM hub_inventory_processed WHERE source_id = :source_id')
    ->execute([':source_id' => $sourceId]);

  $stmtRaw = $pdo->prepare(
    'INSERT INTO hub_inventory_raw
      (source_id, fetch_id, row_index, row_hash, raw_json, created)
     VALUES
      (:source_id, :fetch_id, :row_index, :row_hash, :raw_json, NOW())'
  );

  $stmtProcessed = $pdo->prepare(
    'INSERT INTO hub_inventory_processed
      (raw_id, source_id, fetch_id, row_index, row_hash, customer_id, source_customer_value, json_data, raw_json, show_on_web, published, archived, created, modified)
     VALUES
      (:raw_id, :source_id, :fetch_id, :row_index, :row_hash, :customer_id, :source_customer_value, :json_data, :raw_json, 1, 1, 0, NOW(), NOW())'
  );

  $processed = 0;
  foreach ($rows as $index => $row) {
    if (!is_array($row)) {
      continue;
    }

    $raw = json_encode($row, JSON_UNESCAPED_UNICODE);
    $rowHash = hash('sha256', (string) $raw);
    $sourceCustomerValue = trim((string) hub_import_row_value($row, $sourceField));
    $customerId = hub_portal_inventory_customer_id_for_value($sourceCustomerValue);

    $stmtRaw->execute([
      ':source_id' => $sourceId,
      ':fetch_id' => $fetchId,
      ':row_index' => (int) $index,
      ':row_hash' => $rowHash,
      ':raw_json' => $raw,
    ]);
    $rawId = (int) $pdo->lastInsertId();

    $stmtProcessed->execute([
      ':raw_id' => $rawId,
      ':source_id' => $sourceId,
      ':fetch_id' => $fetchId,
      ':row_index' => (int) $index,
      ':row_hash' => $rowHash,
      ':customer_id' => $customerId,
      ':source_customer_value' => $sourceCustomerValue,
      ':json_data' => $raw,
      ':raw_json' => $raw,
    ]);
    $processed++;
  }

  $pdo->prepare('DELETE FROM hub_inventory_live WHERE source_id = :source_id')
    ->execute([':source_id' => $sourceId]);
  $pdo->prepare(
    'INSERT INTO hub_inventory_live
      (processed_id, source_id, fetch_id, row_index, row_hash, customer_id, source_customer_value, json_data, raw_json, show_on_web, published, archived, created, modified)
     SELECT
      id, source_id, fetch_id, row_index, row_hash, customer_id, source_customer_value, json_data, raw_json, show_on_web, published, archived, NOW(), NOW()
     FROM hub_inventory_processed
     WHERE source_id = :source_id'
  )->execute([':source_id' => $sourceId]);

  return $processed;
}
