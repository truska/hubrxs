<?php
require_once __DIR__ . '/../includes/app/bootstrap.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && ($_GET['run'] ?? '') !== '1') {
  http_response_code(403);
  echo "Add ?run=1 to execute.";
  exit;
}

if (!$DB_OK || !($pdo instanceof PDO)) {
  fwrite(STDERR, "DB not available: {$DB_ERROR}\n");
  exit(1);
}

function exec_sql(PDO $pdo, string $sql): void {
  $pdo->exec($sql);
}

$sql = [];
$sql[] = "CREATE TABLE IF NOT EXISTS hub_customer (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  code VARCHAR(100) NOT NULL,
  contact_email VARCHAR(255) NULL,
  contact_phone VARCHAR(50) NULL,
  notes TEXT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_customer_code (code),
  UNIQUE KEY uq_hub_customer_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_user (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(200) NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  login_enabled TINYINT(1) NOT NULL DEFAULT 1,
  twofa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  force_password_reset TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(64) NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_user_email (email),
  KEY idx_hub_user_customer (customer_id),
  CONSTRAINT fk_hub_user_customer FOREIGN KEY (customer_id) REFERENCES hub_customer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_user_twofa (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  code_sent_to VARCHAR(255) NOT NULL,
  channel ENUM('email') NOT NULL DEFAULT 'email',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  request_ip VARCHAR(64) NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hub_twofa_user FOREIGN KEY (user_id) REFERENCES hub_user(id) ON DELETE CASCADE,
  KEY idx_hub_twofa_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_password_reset (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  request_ip VARCHAR(64) NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hub_reset_user FOREIGN KEY (user_id) REFERENCES hub_user(id) ON DELETE CASCADE,
  KEY idx_hub_reset_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_import_file (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dataset VARCHAR(100) NOT NULL DEFAULT 'SalesOrder',
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  status ENUM('pending','processing','complete','failed') NOT NULL DEFAULT 'pending',
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  processed_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_text TEXT NULL,
  uploaded_by INT UNSIGNED NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  KEY idx_hub_import_file_dataset (dataset),
  KEY idx_hub_import_file_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_import_row (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id INT UNSIGNED NOT NULL,
  customer_code VARCHAR(50) NULL,
  order_type VARCHAR(10) NULL,
  order_nbr VARCHAR(50) NULL,
  branch_id VARCHAR(50) NULL,
  currency VARCHAR(10) NULL,
  order_total DECIMAL(18,4) NULL,
  order_before_tax DECIMAL(18,4) NULL,
  tax_total DECIMAL(18,4) NULL,
  ordered_qty DECIMAL(18,4) NULL,
  date DATETIME NULL,
  requested_on DATETIME NULL,
  sched_shipment DATETIME NULL,
  shipment_date DATETIME NULL,
  description TEXT NULL,
  project VARCHAR(100) NULL,
  account_name VARCHAR(255) NULL,
  last_modified_on DATETIME NULL,
  raw_json JSON NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_import_row_import (import_id),
  KEY idx_import_row_customer_date (customer_code, date),
  CONSTRAINT fk_import_row_file FOREIGN KEY (import_id) REFERENCES hub_import_file(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_sales_order (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  customer_code VARCHAR(50) NULL,
  order_type VARCHAR(10) NOT NULL,
  order_nbr VARCHAR(50) NOT NULL,
  branch_id VARCHAR(50) NOT NULL,
  behavior VARCHAR(50) NULL,
  status VARCHAR(50) NULL,
  currency VARCHAR(10) NULL,
  order_total DECIMAL(18,4) NULL,
  order_before_tax DECIMAL(18,4) NULL,
  tax_total DECIMAL(18,4) NULL,
  ordered_qty DECIMAL(18,4) NULL,
  requested_on DATETIME NULL,
  sched_shipment DATETIME NULL,
  date DATETIME NULL,
  shipment_date DATETIME NULL,
  description TEXT NULL,
  project VARCHAR(100) NULL,
  account_name VARCHAR(255) NULL,
  owner VARCHAR(50) NULL,
  created_by VARCHAR(100) NULL,
  created_on DATETIME NULL,
  last_modified_by VARCHAR(100) NULL,
  last_modified_on DATETIME NULL,
  account_id VARCHAR(50) NULL,
  location_id VARCHAR(50) NULL,
  contact_id VARCHAR(50) NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_order (order_type, order_nbr, branch_id),
  KEY idx_sales_order_customer (customer_id),
  KEY idx_sales_order_code_date (customer_code, date),
  CONSTRAINT fk_sales_order_import FOREIGN KEY (import_id) REFERENCES hub_import_file(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_order_customer FOREIGN KEY (customer_id) REFERENCES hub_customer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

foreach ($sql as $statement) {
  exec_sql($pdo, $statement);
}

$msg = "Schema created/verified.";

// Optional: seed admin user when email provided.
$seedEmail = $isCli ? ($argv[1] ?? '') : (string) ($_GET['seedEmail'] ?? '');
if ($seedEmail !== '') {
  $seedEmail = strtolower(trim($seedEmail));
  $existing = $pdo->prepare('SELECT id FROM hub_user WHERE email = :email LIMIT 1');
  $existing->execute([':email' => $seedEmail]);
  if ($existing->fetchColumn()) {
    $msg .= " User {$seedEmail} already exists.";
  } else {
    $pdo->beginTransaction();
    $stmtCust = $pdo->prepare('INSERT INTO hub_customer (name, code, contact_email, created, modified) VALUES (:name, :code, :email, NOW(), NOW())');
    $customerName = 'Demo Customer';
    $customerCode = 'DEMO';
    $stmtCust->execute([
      ':name' => $customerName,
      ':code' => $customerCode,
      ':email' => $seedEmail,
    ]);
    $customerId = (int) $pdo->lastInsertId();

    $passwordPlain = bin2hex(random_bytes(6));
    $stmtUser = $pdo->prepare(
      'INSERT INTO hub_user
        (customer_id, email, password_hash, display_name, role, login_enabled, twofa_enabled, created, modified)
       VALUES
        (:customer_id, :email, :password_hash, :display_name, :role, 1, 1, NOW(), NOW())'
    );
    $stmtUser->execute([
      ':customer_id' => $customerId,
      ':email' => $seedEmail,
      ':password_hash' => password_hash($passwordPlain, PASSWORD_DEFAULT),
      ':display_name' => 'Hub Admin',
      ':role' => 'admin',
    ]);
    $pdo->commit();
    $msg .= " Seeded admin user {$seedEmail} with password: {$passwordPlain}";
  }
}

if ($isCli) {
  echo $msg . PHP_EOL;
} else {
  echo nl2br(hub_h($msg));
}
