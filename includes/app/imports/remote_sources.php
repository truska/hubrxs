<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/portal_inventory.php';

function hub_import_crypto_key(): string {
  global $DB_PASS;

  $secret = '';
  if (defined('HUB_IMPORT_SECRET')) {
    $secret = (string) HUB_IMPORT_SECRET;
  }
  if ($secret === '') {
    $secret = (string) getenv('HUB_IMPORT_SECRET');
  }
  if ($secret === '') {
    $secret = (string) ($DB_PASS ?? '');
  }
  if ($secret === '') {
    throw new RuntimeException('Import encryption secret is not configured.');
  }

  return sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

function hub_import_encrypt_password(string $password): array {
  $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  $cipher = sodium_crypto_secretbox($password, $nonce, hub_import_crypto_key());
  return ['ciphertext' => base64_encode($cipher), 'nonce' => base64_encode($nonce)];
}

function hub_import_decrypt_password(?string $ciphertext, ?string $nonce): string {
  if (!$ciphertext || !$nonce) {
    return '';
  }
  $cipherRaw = base64_decode($ciphertext, true);
  $nonceRaw = base64_decode($nonce, true);
  if ($cipherRaw === false || $nonceRaw === false) {
    throw new RuntimeException('Stored import password is not valid base64.');
  }
  $plain = sodium_crypto_secretbox_open($cipherRaw, $nonceRaw, hub_import_crypto_key());
  if ($plain === false) {
    throw new RuntimeException('Stored import password could not be decrypted.');
  }
  return $plain;
}

function hub_import_slug(string $value): string {
  $slug = strtolower(trim($value));
  $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?: '';
  $slug = trim($slug, '_');
  return $slug !== '' ? $slug : 'remote_json';
}

function hub_import_parse_datetime($value): ?string {
  $trimmed = trim((string) $value);
  if ($trimmed === '') {
    return null;
  }
  try {
    return (new DateTime($trimmed))->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

function hub_import_num($value): ?float {
  if ($value === null || $value === '') {
    return null;
  }
  return is_numeric($value) ? (float) $value : null;
}

function hub_import_row_value(array $row, string $wantedKey, $default = '') {
  foreach ($row as $key => $value) {
    if (mb_strtolower((string) $key) === mb_strtolower($wantedKey)) {
      return $value;
    }
  }
  return $default;
}

function hub_import_customer_source_norm(string $value): string {
  return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?: $value));
}

function hub_import_customer_match_key(string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }
  $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
  if (is_string($ascii) && $ascii !== '') {
    $value = $ascii;
  }
  $value = mb_strtolower($value);
  $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
  $value = preg_replace('/\s+/', ' ', trim($value)) ?: '';
  return str_replace(' ', '', $value);
}

function hub_import_customer_auto_match_id(string $sourceValue): int {
  global $pdo;

  $sourceKey = hub_import_customer_match_key($sourceValue);
  if ($sourceKey === '' || strlen($sourceKey) < 5 || !hub_table_exists('hub_customer')) {
    return 0;
  }

  $stmt = $pdo->query('SELECT id, name, code FROM hub_customer WHERE archived = 0 ORDER BY id ASC');
  foreach ($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $customer) {
    foreach (['name', 'code'] as $field) {
      $candidate = trim((string) ($customer[$field] ?? ''));
      $candidateKey = hub_import_customer_match_key($candidate);
      if ($candidateKey === '' || strlen($candidateKey) < 5) {
        continue;
      }
      if ($sourceKey === $candidateKey || str_contains($sourceKey, $candidateKey) || str_contains($candidateKey, $sourceKey)) {
        return (int) $customer['id'];
      }
    }
  }

  return 0;
}

function hub_import_customer_mapping_save(string $importName, string $sourceField, string $sourceValue, string $sourceNorm, int $customerId, string $notes): void {
  global $pdo;

  if (!hub_table_exists('hub_customer_mapping')) {
    return;
  }

  $stmt = $pdo->prepare(
    'INSERT INTO hub_customer_mapping
      (import_name, source_field, source_value, source_value_norm, customer_id, notes, archived, created, modified)
     VALUES
      (:import_name, :source_field, :source_value, :source_value_norm, :customer_id, :notes, 0, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      customer_id = VALUES(customer_id), source_value = VALUES(source_value), notes = VALUES(notes), modified = NOW()'
  );
  $stmt->execute([
    ':import_name' => $importName,
    ':source_field' => $sourceField,
    ':source_value' => $sourceValue,
    ':source_value_norm' => $sourceNorm,
    ':customer_id' => $customerId,
    ':notes' => $notes,
  ]);
}
function hub_import_admin_action_open(string $actionKey, string $actionType, string $entityType, ?int $entityId, string $title, string $message, string $source = null): void {
  global $pdo;

  if (!hub_table_exists('hub_admin_action')) {
    return;
  }

  $stmt = $pdo->prepare(
    'INSERT INTO hub_admin_action
      (action_key, action_type, entity_type, entity_id, title, message, status, priority, source, created, modified)
     VALUES
      (:action_key, :action_type, :entity_type, :entity_id, :title, :message, :status, :priority, :source, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      entity_id = VALUES(entity_id),
      title = VALUES(title),
      message = VALUES(message),
      status = IF(status = "complete", status, VALUES(status)),
      modified = NOW()'
  );
  $stmt->execute([
    ':action_key' => $actionKey,
    ':action_type' => $actionType,
    ':entity_type' => $entityType,
    ':entity_id' => $entityId,
    ':title' => $title,
    ':message' => $message,
    ':status' => 'open',
    ':priority' => 'normal',
    ':source' => $source,
  ]);
}

function hub_import_customer_id_for_mapping(string $importName, string $sourceField, string $sourceValue): int {
  global $pdo;

  $sourceValue = trim($sourceValue);
  if ($sourceValue === '' || !hub_table_exists('hub_customer')) {
    return 0;
  }

  $sourceNorm = hub_import_customer_source_norm($sourceValue);
  $stmtCustomer = $pdo->prepare('SELECT id FROM hub_customer WHERE archived = 0 AND (name = :source_value OR code = :source_value) LIMIT 1');
  $stmtCustomer->execute([':source_value' => $sourceValue]);
  $customerId = (int) $stmtCustomer->fetchColumn();
  if ($customerId > 0) {
    hub_import_customer_mapping_save($importName, $sourceField, $sourceValue, $sourceNorm, $customerId, 'Created automatically from exact Hub customer match.');
    return $customerId;
  }

  if (!hub_table_exists('hub_customer_mapping')) {
    return 0;
  }

  $stmtMapping = $pdo->prepare(
    'SELECT customer_id
     FROM hub_customer_mapping
     WHERE import_name = :import_name
       AND source_field = :source_field
       AND source_value_norm = :source_value_norm
       AND archived = 0
     LIMIT 1'
  );
  $stmtMapping->execute([
    ':import_name' => $importName,
    ':source_field' => $sourceField,
    ':source_value_norm' => $sourceNorm,
  ]);
  $mappedCustomerId = (int) $stmtMapping->fetchColumn();
  if ($mappedCustomerId > 0) {
    return $mappedCustomerId;
  }

  $autoCustomerId = hub_import_customer_auto_match_id($sourceValue);
  if ($autoCustomerId > 0) {
    hub_import_customer_mapping_save($importName, $sourceField, $sourceValue, $sourceNorm, $autoCustomerId, 'Created automatically from normalised Hub customer match.');
    return $autoCustomerId;
  }

  $stmtInsert = $pdo->prepare(
    'INSERT INTO hub_customer_mapping
      (import_name, source_field, source_value, source_value_norm, customer_id, notes, archived, created, modified)
     VALUES
      (:import_name, :source_field, :source_value, :source_value_norm, 0, :notes, 0, NOW(), NOW())
     ON DUPLICATE KEY UPDATE source_value = VALUES(source_value), modified = NOW()'
  );
  $stmtInsert->execute([
    ':import_name' => $importName,
    ':source_field' => $sourceField,
    ':source_value' => $sourceValue,
    ':source_value_norm' => $sourceNorm,
    ':notes' => 'Created automatically from import. Allocate this source value to a Hub customer.',
  ]);

  hub_import_admin_action_open(
    'map_customer:' . $importName . ':' . $sourceField . ':' . $sourceNorm,
    'map_customer',
    'customer_mapping',
    null,
    'Map imported customer: ' . $sourceValue,
    'Imported value "' . $sourceValue . '" from ' . $importName . ' / ' . $sourceField . ' has no customer mapping. Allocate it to a Hub customer.',
    $importName
  );

  return 0;
}
function hub_import_customer_id_for_source(string $sourceFeed, string $sourceField, string $sourceValue): ?int {
  return hub_import_customer_id_for_mapping($sourceFeed, $sourceField, $sourceValue) ?: null;
}

function hub_import_source_by_id(int $sourceId): ?array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_import_source')) {
    return null;
  }
  $stmt = $pdo->prepare('SELECT * FROM hub_import_source WHERE id = :id LIMIT 1');
  $stmt->execute([':id' => $sourceId]);
  $source = $stmt->fetch(PDO::FETCH_ASSOC);
  return $source ?: null;
}

function hub_import_source_by_key(string $importKey): ?array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_import_source')) {
    return null;
  }
  $stmt = $pdo->prepare('SELECT * FROM hub_import_source WHERE import_key = :import_key LIMIT 1');
  $stmt->execute([':import_key' => $importKey]);
  $source = $stmt->fetch(PDO::FETCH_ASSOC);
  return $source ?: null;
}

function hub_import_list_sources(bool $includeArchived = false): array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_import_source')) {
    return [];
  }
  $where = $includeArchived ? '1=1' : 'archived = 0';
  $stmt = $pdo->query("SELECT * FROM hub_import_source WHERE {$where} ORDER BY show_on_web DESC, name ASC, id ASC");
  return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function hub_import_recent_fetches(?int $sourceId = null, int $limit = 20): array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_import_fetch')) {
    return [];
  }
  $limit = max(1, min(100, $limit));
  if ($sourceId) {
    $stmt = $pdo->prepare(
      "SELECT f.*, s.name AS source_name, s.import_key
       FROM hub_import_fetch f
       INNER JOIN hub_import_source s ON s.id = f.source_id
       WHERE f.source_id = :source_id
       ORDER BY f.id DESC
       LIMIT {$limit}"
    );
    $stmt->execute([':source_id' => $sourceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  $stmt = $pdo->query(
    "SELECT f.*, s.name AS source_name, s.import_key
     FROM hub_import_fetch f
     INNER JOIN hub_import_source s ON s.id = f.source_id
     ORDER BY f.id DESC
     LIMIT {$limit}"
  );
  return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function hub_import_upsert_source(array $input): int {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    throw new RuntimeException('Database not available.');
  }

  $importKey = hub_import_slug((string) ($input['import_key'] ?? $input['name'] ?? 'remote_json'));
  $name = trim((string) ($input['name'] ?? $importKey));
  $url = trim((string) ($input['source_url'] ?? ''));
  $handler = trim((string) ($input['handler'] ?? 'generic_json'));
  $authType = trim((string) ($input['auth_type'] ?? 'basic'));
  $username = trim((string) ($input['auth_username'] ?? ''));
  $notes = trim((string) ($input['notes'] ?? ''));
  $showOnWeb = !empty($input['show_on_web']) ? 1 : 0;
  $archived = !empty($input['archived']) ? 1 : 0;
  $timeout = max(5, min(300, (int) ($input['timeout_seconds'] ?? 60)));
  if ($name === '' || $url === '') {
    throw new InvalidArgumentException('Import source name and URL are required.');
  }

  $password = (string) ($input['auth_password'] ?? '');
  $encrypted = $password !== '' ? hub_import_encrypt_password($password) : null;
  $existing = hub_import_source_by_key($importKey);
  if ($existing) {
    $sets = [
      'name = :name', 'source_url = :source_url', 'handler = :handler', 'auth_type = :auth_type',
      'auth_username = :auth_username', 'notes = :notes', 'timeout_seconds = :timeout_seconds',
      'show_on_web = :show_on_web', 'archived = :archived', 'modified = NOW()',
    ];
    $params = [
      ':id' => (int) $existing['id'], ':name' => $name, ':source_url' => $url, ':handler' => $handler,
      ':auth_type' => $authType, ':auth_username' => $username, ':notes' => $notes !== '' ? $notes : null,
      ':timeout_seconds' => $timeout, ':show_on_web' => $showOnWeb, ':archived' => $archived,
    ];
    if ($encrypted) {
      $sets[] = 'auth_password_ciphertext = :auth_password_ciphertext';
      $sets[] = 'auth_password_nonce = :auth_password_nonce';
      $params[':auth_password_ciphertext'] = $encrypted['ciphertext'];
      $params[':auth_password_nonce'] = $encrypted['nonce'];
    }
    $stmt = $pdo->prepare('UPDATE hub_import_source SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($params);
    return (int) $existing['id'];
  }

  $stmt = $pdo->prepare(
    'INSERT INTO hub_import_source
      (import_key, name, notes, source_url, handler, auth_type, auth_username, auth_password_ciphertext, auth_password_nonce, timeout_seconds, show_on_web, archived, created, modified)
     VALUES
      (:import_key, :name, :notes, :source_url, :handler, :auth_type, :auth_username, :auth_password_ciphertext, :auth_password_nonce, :timeout_seconds, :show_on_web, :archived, NOW(), NOW())'
  );
  $stmt->execute([
    ':import_key' => $importKey, ':name' => $name, ':notes' => $notes !== '' ? $notes : null,
    ':source_url' => $url, ':handler' => $handler, ':auth_type' => $authType, ':auth_username' => $username,
    ':auth_password_ciphertext' => $encrypted['ciphertext'] ?? null, ':auth_password_nonce' => $encrypted['nonce'] ?? null,
    ':timeout_seconds' => $timeout, ':show_on_web' => $showOnWeb, ':archived' => $archived,
  ]);
  return (int) $pdo->lastInsertId();
}

function hub_import_fetch_body(array $source): array {
  $ch = curl_init((string) $source['source_url']);
  if (!$ch) {
    return ['ok' => false, 'body' => '', 'error' => 'Unable to initialise cURL.'];
  }
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => (int) ($source['timeout_seconds'] ?? 60),
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_SSL_VERIFYPEER => !empty($source['verify_tls']),
    CURLOPT_SSL_VERIFYHOST => !empty($source['verify_tls']) ? 2 : 0,
  ]);
  if (($source['auth_type'] ?? '') === 'basic') {
    $password = hub_import_decrypt_password($source['auth_password_ciphertext'] ?? null, $source['auth_password_nonce'] ?? null);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, (string) $source['auth_username'] . ':' . $password);
  }
  $body = curl_exec($ch);
  $error = curl_error($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  curl_close($ch);
  if ($body === false) {
    return ['ok' => false, 'body' => '', 'error' => $error ?: 'cURL request failed.', 'http_status' => $status, 'content_type' => $contentType, 'url' => $effectiveUrl];
  }
  if ($status < 200 || $status >= 300) {
    return ['ok' => false, 'body' => (string) $body, 'error' => 'Remote returned HTTP ' . $status . '.', 'http_status' => $status, 'content_type' => $contentType, 'url' => $effectiveUrl];
  }
  return ['ok' => true, 'body' => (string) $body, 'error' => null, 'http_status' => $status, 'content_type' => $contentType, 'url' => $effectiveUrl];
}

function hub_import_archive_key(array $source): string {
  $haystack = mb_strtolower((string) ($source['handler'] ?? '') . ' ' . (string) ($source['import_key'] ?? '') . ' ' . (string) ($source['name'] ?? ''));
  if (str_contains($haystack, 'inventory')) {
    return 'inventory';
  }
  if (str_contains($haystack, 'so_portal') || str_contains($haystack, 'sales') || str_contains($haystack, 'order')) {
    return 'so';
  }
  return hub_import_slug((string) ($source['handler'] ?? $source['import_key'] ?? 'import'));
}

function hub_import_archive_json_body(array $source, int $fetchId, string $body, int $keep = 3): ?string {
  $body = trim($body);
  if ($body === '') {
    return null;
  }
  $dir = dirname(__DIR__, 3) . '/sourcedata';
  if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    return null;
  }
  $key = hub_import_archive_key($source);
  $file = $dir . '/' . $key . '_' . date('Ymd_His') . '_fetch-' . $fetchId . '.json';
  if (file_put_contents($file, $body) === false) {
    return null;
  }
  $files = glob($dir . '/' . $key . '_*_fetch-*.json') ?: [];
  usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
  foreach (array_slice($files, max(0, $keep)) as $oldFile) {
    if (is_file($oldFile)) {
      unlink($oldFile);
    }
  }
  return $file;
}

function hub_import_decode_value_rows(string $jsonText): array {
  $data = json_decode($jsonText, true);
  if (!is_array($data)) {
    throw new RuntimeException('Remote response is not valid JSON.');
  }
  $rows = $data['value'] ?? null;
  if (!is_array($rows)) {
    throw new RuntimeException('Remote JSON does not contain a value array.');
  }
  return $rows;
}

function hub_import_start_fetch(int $sourceId, ?int $triggeredBy): int {
  global $pdo;
  $stmt = $pdo->prepare('INSERT INTO hub_import_fetch (source_id, status, triggered_by, started_at) VALUES (:source_id, :status, :triggered_by, NOW())');
  $stmt->execute([':source_id' => $sourceId, ':status' => 'running', ':triggered_by' => $triggeredBy]);
  return (int) $pdo->lastInsertId();
}

function hub_import_finish_fetch(int $fetchId, array $values): void {
  global $pdo;
  $stmt = $pdo->prepare(
    'UPDATE hub_import_fetch
     SET status = :status, http_status = :http_status, content_type = :content_type,
         bytes_downloaded = :bytes_downloaded, sha256 = :sha256, row_count = :row_count,
         processed_count = :processed_count, skipped = :skipped, error_text = :error_text,
         finished_at = NOW()
     WHERE id = :id'
  );
  $stmt->execute([
    ':id' => $fetchId,
    ':status' => $values['status'] ?? 'failed',
    ':http_status' => $values['http_status'] ?? null,
    ':content_type' => $values['content_type'] ?? null,
    ':bytes_downloaded' => $values['bytes_downloaded'] ?? 0,
    ':sha256' => $values['sha256'] ?? null,
    ':row_count' => $values['row_count'] ?? 0,
    ':processed_count' => $values['processed_count'] ?? 0,
    ':skipped' => !empty($values['skipped']) ? 1 : 0,
    ':error_text' => $values['error_text'] ?? null,
  ]);
}

function hub_import_previous_complete_sha(int $sourceId): ?string {
  global $pdo;
  $stmt = $pdo->prepare("SELECT sha256 FROM hub_import_fetch WHERE source_id = :source_id AND status = 'complete' AND sha256 IS NOT NULL ORDER BY id DESC LIMIT 1");
  $stmt->execute([':source_id' => $sourceId]);
  $sha = $stmt->fetchColumn();
  return $sha ? (string) $sha : null;
}

function hub_import_source_row_key(array $row, array $fields): string {
  $parts = [];
  foreach ($fields as $field) {
    $parts[] = trim((string) hub_import_row_value($row, $field));
  }
  $joined = implode('|', $parts);
  if (trim(str_replace('|', '', $joined)) === '') {
    return hash('sha256', (string) json_encode($row, JSON_UNESCAPED_UNICODE));
  }
  return hash('sha256', $joined);
}

function hub_import_project_id_for_code(string $projectCode, ?int $customerId = null, string $source = null): ?int {
  global $pdo;

  $projectCode = trim($projectCode);
  $customerId = $customerId !== null && $customerId > 0 ? $customerId : null;
  if ($projectCode === '' || !hub_table_exists('hub_project')) {
    return null;
  }

  $stmt = $pdo->prepare('SELECT id, customer_id FROM hub_project WHERE code = :code LIMIT 1');
  $stmt->execute([':code' => $projectCode]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($existing) {
    $projectId = (int) $existing['id'];
    $existingCustomerId = isset($existing['customer_id']) ? (int) $existing['customer_id'] : 0;
    if ($customerId !== null && $existingCustomerId <= 0) {
      $stmtUpdate = $pdo->prepare('UPDATE hub_project SET customer_id = :customer_id, modified = NOW() WHERE id = :id AND customer_id IS NULL LIMIT 1');
      $stmtUpdate->execute([':customer_id' => $customerId, ':id' => $projectId]);
    } elseif ($customerId !== null && $existingCustomerId > 0 && $existingCustomerId !== $customerId) {
      hub_import_admin_action_open(
        'review_project_customer:' . $projectCode,
        'review_project_customer',
        'project',
        $projectId,
        'Review project customer: ' . $projectCode,
        'Project ' . $projectCode . ' was imported for customer ID ' . $customerId . ' but is already assigned to customer ID ' . $existingCustomerId . '. Confirm whether the project code is shared or the customer assignment needs correction.',
        $source
      );
    }
    return $projectId;
  }

  $stmtInsert = $pdo->prepare('INSERT INTO hub_project (customer_id, code, name, notes, source, needs_review, show_on_web, archived, created, modified) VALUES (:customer_id, :code, NULL, :notes, :source, 1, 1, 0, NOW(), NOW())');
  $stmtInsert->execute([
    ':customer_id' => $customerId,
    ':code' => $projectCode,
    ':notes' => 'Created automatically from import. Please complete project details.',
    ':source' => $source,
  ]);
  $projectId = (int) $pdo->lastInsertId();
  hub_import_admin_action_open('complete_project:' . $projectCode, 'complete_project', 'project', $projectId, 'Complete project details: ' . $projectCode, 'Project ' . $projectCode . ' was found in an import but does not yet have complete hub details.', $source);
  return $projectId;
}

function hub_import_apply_so_portal_lines(int $sourceId, int $fetchId, array $rows): int {
  global $pdo;

  $pdo->prepare('DELETE FROM hub_so_raw WHERE source_id = :source_id')->execute([':source_id' => $sourceId]);
  $pdo->prepare('DELETE FROM hub_so_processed WHERE source_id = :source_id')->execute([':source_id' => $sourceId]);

  $stmtRaw = $pdo->prepare('INSERT INTO hub_so_raw (source_id, fetch_id, row_index, row_hash, raw_json, created) VALUES (:source_id, :fetch_id, :row_index, :row_hash, :raw_json, NOW())');
  $stmtProcessed = $pdo->prepare(
    'INSERT INTO hub_so_processed
      (raw_id, source_id, fetch_id, row_index, source_row_key, row_hash, customer_id, source_customer_field, source_customer_value, order_nbr, shipment_nbr, customer_name, customer_ref, project, description, external_reference, status, requested_on, shipment_date, line_description, ship_via, tracking_number, lot_serial_nbr, quantity, order_type, base_type, project_id, line_nbr, allocation_id, raw_json, show_on_web, published, archived, created, modified)
     VALUES
      (:raw_id, :source_id, :fetch_id, :row_index, :source_row_key, :row_hash, :customer_id, :source_customer_field, :source_customer_value, :order_nbr, :shipment_nbr, :customer_name, :customer_ref, :project, :description, :external_reference, :status, :requested_on, :shipment_date, :line_description, :ship_via, :tracking_number, :lot_serial_nbr, :quantity, :order_type, :base_type, :project_id, :line_nbr, :allocation_id, :raw_json, 1, 1, 0, NOW(), NOW())'
  );

  $processed = 0;
  foreach ($rows as $index => $row) {
    if (!is_array($row)) {
      continue;
    }
    $raw = json_encode($row, JSON_UNESCAPED_UNICODE);
    $rowHash = hash('sha256', (string) $raw);
    $sourceRowKey = hub_import_source_row_key($row, ['OrderType', 'OrderNbr', 'LineNbr', 'AllocationID', 'ShipmentNbr', 'LotSerialNbr']);
    $sourceCustomerField = 'CustomerName';
    $sourceCustomerValue = (string) hub_import_row_value($row, $sourceCustomerField);
    if (trim($sourceCustomerValue) === '') {
      $sourceCustomerValue = (string) hub_import_row_value($row, 'CustomerName');
    }
    $customerId = hub_import_customer_id_for_mapping('so_portal_lines', $sourceCustomerField, $sourceCustomerValue);
    hub_import_project_id_for_code((string) hub_import_row_value($row, 'Project'), $customerId, 'so_portal_lines');

    $stmtRaw->execute([
      ':source_id' => $sourceId, ':fetch_id' => $fetchId, ':row_index' => (int) $index,
      ':row_hash' => $rowHash, ':raw_json' => $raw,
    ]);
    $rawId = (int) $pdo->lastInsertId();

    $stmtProcessed->execute([
      ':raw_id' => $rawId, ':source_id' => $sourceId, ':fetch_id' => $fetchId, ':row_index' => (int) $index,
      ':source_row_key' => $sourceRowKey, ':row_hash' => $rowHash, ':customer_id' => $customerId,
      ':source_customer_field' => $sourceCustomerField, ':source_customer_value' => $sourceCustomerValue,
      ':order_nbr' => (string) hub_import_row_value($row, 'OrderNbr'),
      ':shipment_nbr' => (string) hub_import_row_value($row, 'ShipmentNbr'),
      ':customer_name' => $sourceCustomerValue,
      ':customer_ref' => (string) hub_import_row_value($row, 'description_2'),
      ':project' => (string) hub_import_row_value($row, 'Project'),
      ':description' => (string) hub_import_row_value($row, 'Description'),
      ':external_reference' => (string) hub_import_row_value($row, 'ExternalReference'),
      ':status' => (string) hub_import_row_value($row, 'Status'),
      ':requested_on' => hub_import_parse_datetime(hub_import_row_value($row, 'RequestedOn', null)),
      ':shipment_date' => hub_import_parse_datetime(hub_import_row_value($row, 'ShipmentDate', null)),
      ':line_description' => (string) hub_import_row_value($row, 'LineDescription'),
      ':ship_via' => (string) hub_import_row_value($row, 'ShipVia'),
      ':tracking_number' => (string) hub_import_row_value($row, 'TrackingNumber'),
      ':lot_serial_nbr' => (string) hub_import_row_value($row, 'LotSerialNbr'),
      ':quantity' => hub_import_num(hub_import_row_value($row, 'Quantity', null)),
      ':order_type' => (string) hub_import_row_value($row, 'OrderType'),
      ':base_type' => (string) hub_import_row_value($row, 'BaseType'),
      ':project_id' => (string) hub_import_row_value($row, 'ProjectID'),
      ':line_nbr' => (string) hub_import_row_value($row, 'LineNbr'),
      ':allocation_id' => (string) hub_import_row_value($row, 'AllocationID'),
      ':raw_json' => $raw,
    ]);
    $processed++;
  }

  $pdo->prepare('DELETE FROM hub_so_live WHERE source_id = :source_id')->execute([':source_id' => $sourceId]);
  $pdo->prepare(
    'INSERT INTO hub_so_live
      (processed_id, source_id, fetch_id, row_index, source_row_key, row_hash, customer_id, source_customer_field, source_customer_value, order_nbr, shipment_nbr, customer_name, customer_ref, project, description, external_reference, status, requested_on, shipment_date, line_description, ship_via, tracking_number, lot_serial_nbr, quantity, order_type, base_type, project_id, line_nbr, allocation_id, raw_json, show_on_web, published, archived, created, modified)
     SELECT
      id, source_id, fetch_id, row_index, source_row_key, row_hash, customer_id, source_customer_field, source_customer_value, order_nbr, shipment_nbr, customer_name, customer_ref, project, description, external_reference, status, requested_on, shipment_date, line_description, ship_via, tracking_number, lot_serial_nbr, quantity, order_type, base_type, project_id, line_nbr, allocation_id, raw_json, show_on_web, published, archived, NOW(), NOW()
     FROM hub_so_processed
     WHERE source_id = :source_id'
  )->execute([':source_id' => $sourceId]);

  return $processed;
}

function hub_import_apply_handler(array $source, int $fetchId, array $rows): int {
  $handler = (string) ($source['handler'] ?? 'generic_json');
  $sourceId = (int) $source['id'];
  if ($handler === 'so_portal_lines') {
    return hub_import_apply_so_portal_lines($sourceId, $fetchId, $rows);
  }
  if ($handler === 'portal_inventory') {
    return hub_import_apply_portal_inventory_v2($sourceId, $fetchId, $rows);
  }
  return count($rows);
}

function hub_import_run_source(int $sourceId, ?int $triggeredBy = null, bool $force = false): array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO)) {
    return ['ok' => false, 'message' => 'Database not available.'];
  }
  $source = hub_import_source_by_id($sourceId);
  if (!$source || !empty($source['archived'])) {
    return ['ok' => false, 'message' => 'Import source not found or archived.'];
  }

  $fetchId = hub_import_start_fetch($sourceId, $triggeredBy);
  try {
    $fetched = hub_import_fetch_body($source);
    $body = (string) ($fetched['body'] ?? '');
    $sha = $body !== '' ? hash('sha256', $body) : null;
    $bytes = strlen($body);

    if (empty($fetched['ok'])) {
      hub_import_finish_fetch($fetchId, [
        'status' => 'failed', 'http_status' => $fetched['http_status'] ?? null,
        'content_type' => $fetched['content_type'] ?? null, 'bytes_downloaded' => $bytes,
        'sha256' => $sha, 'error_text' => $fetched['error'] ?? 'Fetch failed.',
      ]);
      return ['ok' => false, 'message' => $fetched['error'] ?? 'Fetch failed.', 'fetch_id' => $fetchId];
    }

    hub_import_archive_json_body($source, $fetchId, $body);

    if (!$force && $sha && hub_import_previous_complete_sha($sourceId) === $sha) {
      hub_import_finish_fetch($fetchId, [
        'status' => 'complete', 'http_status' => $fetched['http_status'] ?? null,
        'content_type' => $fetched['content_type'] ?? null, 'bytes_downloaded' => $bytes,
        'sha256' => $sha, 'skipped' => true,
      ]);
      return ['ok' => true, 'message' => 'Skipped unchanged feed.', 'fetch_id' => $fetchId, 'skipped' => true];
    }

    $rows = hub_import_decode_value_rows($body);
    $pdo->beginTransaction();
    $staged = count($rows);
    $processed = hub_import_apply_handler($source, $fetchId, $rows);
    $pdo->commit();

    hub_import_finish_fetch($fetchId, [
      'status' => 'complete', 'http_status' => $fetched['http_status'] ?? null,
      'content_type' => $fetched['content_type'] ?? null, 'bytes_downloaded' => $bytes,
      'sha256' => $sha, 'row_count' => count($rows), 'processed_count' => $processed,
    ]);

    return [
      'ok' => true,
      'message' => 'Imported ' . $processed . ' of ' . count($rows) . ' rows.',
      'fetch_id' => $fetchId,
      'rows' => count($rows),
      'processed' => $processed,
      'staged' => $staged,
      'sha256' => $sha,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    hub_import_finish_fetch($fetchId, ['status' => 'failed', 'error_text' => $e->getMessage()]);
    return ['ok' => false, 'message' => 'Import failed: ' . $e->getMessage(), 'fetch_id' => $fetchId];
  }
}

function hub_import_run_all(?int $triggeredBy = null, bool $force = false): array {
  $results = [];
  foreach (hub_import_list_sources(false) as $source) {
    $results[] = ['source' => $source, 'result' => hub_import_run_source((int) $source['id'], $triggeredBy, $force)];
  }
  return $results;
}