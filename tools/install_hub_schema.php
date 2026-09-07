<?php
require_once __DIR__ . '/../includes/app/bootstrap.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
  http_response_code(403);
  echo "CLI only.";
  exit;
}

if (!$DB_OK || !($pdo instanceof PDO)) {
  fwrite(STDERR, "DB not available: {$DB_ERROR}\n");
  exit(1);
}

function exec_sql(PDO $pdo, string $sql): void {
  if (preg_match("/^CREATE TABLE IF NOT EXISTS `?([a-zA-Z0-9_]+)`?\s*\(/i", $sql, $matches)) {
    $tableName = (string) $matches[1];
    if (hub_table_exists($tableName)) {
      return;
    }
  }

  try {
    $pdo->exec($sql);
  } catch (PDOException $e) {
    if (isset($tableName) && hub_table_exists($tableName)) {
      return;
    }
    throw $e;
  }
}

function install_table_exists(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
  $stmt->execute([':table' => $table]);
  return (bool) $stmt->fetchColumn();
}

$sql = [];
$sql[] = "CREATE TABLE IF NOT EXISTS hub_customer (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  code VARCHAR(100) NOT NULL,
  contact_email VARCHAR(255) NULL,
  contact_phone VARCHAR(50) NULL,
  address_line1 VARCHAR(255) NULL,
  address_line2 VARCHAR(255) NULL,
  address_line3 VARCHAR(255) NULL,
  address_city VARCHAR(120) NULL,
  address_county VARCHAR(120) NULL,
  address_postcode VARCHAR(40) NULL,
  address_country VARCHAR(120) NULL,
  key_contact_user_id INT UNSIGNED NULL,
  customer_type ENUM('customer','prospect') NOT NULL DEFAULT 'customer',
  notes TEXT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_customer_code (code),
  UNIQUE KEY uq_hub_customer_name (name),
  KEY idx_hub_customer_key_contact (key_contact_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_dashboard_button (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  button_key VARCHAR(100) NOT NULL,
  title VARCHAR(200) NOT NULL,
  href VARCHAR(255) NOT NULL DEFAULT 'dashboard.php',
  css_class VARCHAR(120) NOT NULL DEFAULT '',
  image_url VARCHAR(255) NOT NULL DEFAULT '',
  count_key VARCHAR(50) NOT NULL DEFAULT '',
  show_customer TINYINT(1) NOT NULL DEFAULT 0,
  show_prospect TINYINT(1) NOT NULL DEFAULT 0,
  show_staff TINYINT(1) NOT NULL DEFAULT 0,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 100,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_dashboard_button_key (button_key),
  KEY idx_hub_dashboard_button_visible (show_on_web, archived, sort),
  KEY idx_hub_dashboard_button_customer (show_customer, show_on_web, archived, sort),
  KEY idx_hub_dashboard_button_prospect (show_prospect, show_on_web, archived, sort),
  KEY idx_hub_dashboard_button_staff (show_staff, show_on_web, archived, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_testimonial (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_company_name VARCHAR(200) NOT NULL,
  contact_name VARCHAR(200) NOT NULL DEFAULT '',
  job_title VARCHAR(200) NOT NULL DEFAULT '',
  client_logo VARCHAR(255) NOT NULL DEFAULT '',
  testimonial_text TEXT NOT NULL,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 100,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_hub_testimonial_visible (show_on_web, archived, sort),
  KEY idx_hub_testimonial_company (client_company_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_customer_mapping (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_name VARCHAR(100) NOT NULL,
  source_field VARCHAR(100) NOT NULL,
  source_value VARCHAR(255) NOT NULL,
  source_value_norm VARCHAR(255) NOT NULL,
  customer_id INT UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_customer_mapping_source (import_name, source_field, source_value_norm),
  KEY idx_hub_customer_mapping_customer (customer_id),
  KEY idx_hub_customer_mapping_review (customer_id, archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_inventory_raw (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NULL,
  fetch_id INT UNSIGNED NULL,
  row_index INT UNSIGNED NOT NULL,
  row_hash CHAR(64) NOT NULL,
  raw_json JSON NOT NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hub_inventory_raw_source (source_id, fetch_id),
  KEY idx_hub_inventory_raw_hash (row_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_inventory_processed (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  raw_id BIGINT UNSIGNED NULL,
  source_id INT UNSIGNED NULL,
  fetch_id INT UNSIGNED NULL,
  row_index INT UNSIGNED NOT NULL,
  row_hash CHAR(64) NOT NULL,
  customer_id INT UNSIGNED NOT NULL DEFAULT 0,
  source_customer_value VARCHAR(255) NULL,
  json_data JSON NOT NULL,
  raw_json JSON NOT NULL,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  published TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_hub_inventory_processed_customer (customer_id),
  KEY idx_hub_inventory_processed_source (source_id, fetch_id),
  KEY idx_hub_inventory_processed_visible (show_on_web, published, archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_inventory_live (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  processed_id BIGINT UNSIGNED NULL,
  source_id INT UNSIGNED NULL,
  fetch_id INT UNSIGNED NULL,
  row_index INT UNSIGNED NOT NULL,
  row_hash CHAR(64) NOT NULL,
  customer_id INT UNSIGNED NOT NULL DEFAULT 0,
  source_customer_value VARCHAR(255) NULL,
  json_data JSON NOT NULL,
  raw_json JSON NOT NULL,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  published TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_hub_inventory_live_customer (customer_id),
  KEY idx_hub_inventory_live_source (source_id, fetch_id),
  KEY idx_hub_inventory_live_visible (show_on_web, published, archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_so_raw (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NULL,
  fetch_id INT UNSIGNED NULL,
  row_index INT UNSIGNED NOT NULL,
  row_hash CHAR(64) NOT NULL,
  raw_json JSON NOT NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hub_so_raw_source (source_id, fetch_id),
  KEY idx_hub_so_raw_hash (row_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_so_processed (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  raw_id BIGINT UNSIGNED NULL,
  source_id INT UNSIGNED NOT NULL,
  fetch_id INT UNSIGNED NOT NULL,
  row_index INT UNSIGNED NOT NULL,
  source_row_key CHAR(64) NOT NULL,
  row_hash CHAR(64) NOT NULL,
  customer_id INT UNSIGNED NOT NULL DEFAULT 0,
  source_customer_field VARCHAR(100) NULL,
  source_customer_value VARCHAR(255) NULL,
  order_nbr VARCHAR(50) NULL,
  shipment_nbr VARCHAR(50) NULL,
  customer_name VARCHAR(255) NULL,
  customer_ref VARCHAR(255) NULL,
  project VARCHAR(100) NULL,
  description TEXT NULL,
  external_reference VARCHAR(255) NULL,
  status VARCHAR(100) NULL,
  requested_on DATETIME NULL,
  shipment_date DATETIME NULL,
  line_description TEXT NULL,
  ship_via VARCHAR(100) NULL,
  tracking_number VARCHAR(255) NULL,
  lot_serial_nbr VARCHAR(100) NULL,
  quantity DECIMAL(18,4) NULL,
  order_type VARCHAR(50) NULL,
  base_type VARCHAR(50) NULL,
  project_id VARCHAR(100) NULL,
  line_nbr VARCHAR(50) NULL,
  allocation_id VARCHAR(100) NULL,
  raw_json JSON NULL,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  published TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_so_processed_source_row (source_id, source_row_key),
  KEY idx_hub_so_processed_order (order_type, order_nbr),
  KEY idx_hub_so_processed_customer_id (customer_id),
  KEY idx_hub_so_processed_source_customer (source_customer_field, source_customer_value),
  KEY idx_hub_so_processed_customer (customer_name),
  KEY idx_hub_so_processed_customer_ref (customer_ref),
  KEY idx_hub_so_processed_status (status),
  KEY idx_hub_so_processed_fetch (fetch_id),
  KEY idx_hub_so_processed_visible (show_on_web, published, archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_so_live (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  processed_id BIGINT UNSIGNED NULL,
  source_id INT UNSIGNED NOT NULL,
  fetch_id INT UNSIGNED NOT NULL,
  row_index INT UNSIGNED NOT NULL,
  source_row_key CHAR(64) NOT NULL,
  row_hash CHAR(64) NOT NULL,
  customer_id INT UNSIGNED NOT NULL DEFAULT 0,
  source_customer_field VARCHAR(100) NULL,
  source_customer_value VARCHAR(255) NULL,
  order_nbr VARCHAR(50) NULL,
  shipment_nbr VARCHAR(50) NULL,
  customer_name VARCHAR(255) NULL,
  customer_ref VARCHAR(255) NULL,
  project VARCHAR(100) NULL,
  description TEXT NULL,
  external_reference VARCHAR(255) NULL,
  status VARCHAR(100) NULL,
  requested_on DATETIME NULL,
  shipment_date DATETIME NULL,
  line_description TEXT NULL,
  ship_via VARCHAR(100) NULL,
  tracking_number VARCHAR(255) NULL,
  lot_serial_nbr VARCHAR(100) NULL,
  quantity DECIMAL(18,4) NULL,
  order_type VARCHAR(50) NULL,
  base_type VARCHAR(50) NULL,
  project_id VARCHAR(100) NULL,
  line_nbr VARCHAR(50) NULL,
  allocation_id VARCHAR(100) NULL,
  raw_json JSON NULL,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  published TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_so_live_source_row (source_id, source_row_key),
  KEY idx_hub_so_live_order (order_type, order_nbr),
  KEY idx_hub_so_live_customer_id (customer_id),
  KEY idx_hub_so_live_source_customer (source_customer_field, source_customer_value),
  KEY idx_hub_so_live_customer (customer_name),
  KEY idx_hub_so_live_customer_ref (customer_ref),
  KEY idx_hub_so_live_status (status),
  KEY idx_hub_so_live_fetch (fetch_id),
  KEY idx_hub_so_live_visible (show_on_web, published, archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_report_column (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_key VARCHAR(60) NOT NULL,
  column_key VARCHAR(100) NOT NULL,
  source_field VARCHAR(100) NOT NULL,
  data_field VARCHAR(100) NOT NULL,
  heading VARCHAR(150) NOT NULL,
  display_type ENUM('text','date','number','link','badge') NOT NULL DEFAULT 'text',
  filter_type ENUM('none','search','select','date') NOT NULL DEFAULT 'search',
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  at_a_glance TINYINT(1) NOT NULL DEFAULT 0,
  is_link TINYINT(1) NOT NULL DEFAULT 0,
  is_report TINYINT(1) NOT NULL DEFAULT 0,
  is_table TINYINT(1) NOT NULL DEFAULT 1,
  is_export TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 100,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_report_column_key (report_key, column_key),
  KEY idx_hub_report_column_visible (report_key, show_on_web, archived, sort),
  KEY idx_hub_report_column_glance (report_key, at_a_glance, archived, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_report_value_colour (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_key VARCHAR(60) NOT NULL,
  column_key VARCHAR(100) NOT NULL,
  value_key VARCHAR(150) NOT NULL,
  display_label VARCHAR(150) NULL,
  colour VARCHAR(20) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 100,
  show_when_zero TINYINT(1) NOT NULL DEFAULT 1,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_report_value_colour (report_key, column_key, value_key),
  KEY idx_hub_report_value_colour_visible (report_key, column_key, show_on_web, archived, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$sql[] = "CREATE TABLE IF NOT EXISTS hub_user_role (
  role_key VARCHAR(50) NOT NULL PRIMARY KEY,
  label VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  icon_class VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-user',
  rank INT NOT NULL DEFAULT 0,
  can_login TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_hub_user_role_rank (archived, rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_content_page (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page_key VARCHAR(100) NOT NULL,
  slug VARCHAR(150) NULL,
  title VARCHAR(200) NOT NULL,
  meta_title VARCHAR(255) NULL,
  meta_description VARCHAR(320) NULL,
  canonical_url VARCHAR(255) NULL,
  robots VARCHAR(50) NOT NULL DEFAULT 'index,follow',
  body MEDIUMTEXT NULL,
  published TINYINT(1) NOT NULL DEFAULT 1,
  modified_by INT UNSIGNED NULL,
  updated_at DATETIME NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_content_page_key (page_key),
  UNIQUE KEY uq_hub_content_page_slug (slug),
  KEY idx_hub_content_page_published (published, page_key),
  KEY idx_hub_content_page_modified_by (modified_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$sql[] = "CREATE TABLE IF NOT EXISTS hub_user (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(200) NULL,
  job_title VARCHAR(200) NULL,
  phone VARCHAR(50) NULL,
  linkedin VARCHAR(255) NULL,
  image VARCHAR(255) NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'user',
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
  KEY idx_hub_user_role (role),
  CONSTRAINT fk_hub_user_customer FOREIGN KEY (customer_id) REFERENCES hub_customer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_key_info_role (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(100) NOT NULL,
  label VARCHAR(200) NOT NULL,
  section_label VARCHAR(200) NOT NULL,
  default_sort INT NOT NULL DEFAULT 100,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_key_info_role_key (role_key),
  KEY idx_hub_key_info_role_visible (show_on_web, archived, default_sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_customer_key_info_user (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  sort INT NOT NULL DEFAULT 100,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_customer_key_info_role_customer_sort (role_id, customer_id, sort),
  KEY idx_hub_customer_key_info_customer (customer_id, show_on_web, archived, sort),
  KEY idx_hub_customer_key_info_user (user_id),
  CONSTRAINT fk_hub_customer_key_info_role FOREIGN KEY (role_id) REFERENCES hub_key_info_role(id) ON DELETE CASCADE,
  CONSTRAINT fk_hub_customer_key_info_customer FOREIGN KEY (customer_id) REFERENCES hub_customer(id) ON DELETE CASCADE,
  CONSTRAINT fk_hub_customer_key_info_user FOREIGN KEY (user_id) REFERENCES hub_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_project (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NULL,
  code VARCHAR(100) NOT NULL,
  name VARCHAR(255) NULL,
  notes TEXT NULL,
  source VARCHAR(100) NULL,
  needs_review TINYINT(1) NOT NULL DEFAULT 1,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_project_code (code),
  KEY idx_hub_project_customer (customer_id),
  KEY idx_hub_project_review (needs_review, archived),
  CONSTRAINT fk_hub_project_customer FOREIGN KEY (customer_id) REFERENCES hub_customer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_admin_action (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action_key VARCHAR(200) NOT NULL,
  action_type VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id INT UNSIGNED NULL,
  assigned_user_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NULL,
  status ENUM('open','complete','dismissed') NOT NULL DEFAULT 'open',
  priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  source VARCHAR(100) NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_admin_action_key (action_key),
  KEY idx_hub_admin_action_status (status, priority),
  KEY idx_hub_admin_action_entity (entity_type, entity_id),
  KEY idx_hub_admin_action_assigned (assigned_user_id, status),
  CONSTRAINT fk_hub_admin_action_user FOREIGN KEY (assigned_user_id) REFERENCES hub_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_user_action_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  user_email VARCHAR(255) NULL,
  action_key VARCHAR(100) NOT NULL,
  action_title VARCHAR(255) NOT NULL,
  action_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  table_name VARCHAR(100) NULL,
  record_id VARCHAR(100) NULL,
  sql_text MEDIUMTEXT NULL,
  ip_address VARCHAR(64) NULL,
  request_method VARCHAR(20) NULL,
  request_uri TEXT NULL,
  user_agent TEXT NULL,
  details_json JSON NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_hub_user_action_user_time (user_id, action_time),
  KEY idx_hub_user_action_key_time (action_key, action_time),
  KEY idx_hub_user_action_record (table_name, record_id),
  KEY idx_hub_user_action_archived (archived, action_time),
  CONSTRAINT fk_hub_user_action_user FOREIGN KEY (user_id) REFERENCES hub_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_dashboard_banner (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  image VARCHAR(255) NOT NULL DEFAULT '',
  alt_text VARCHAR(255) NULL,
  sort INT NOT NULL DEFAULT 100,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_dashboard_banner_name (name),
  KEY idx_hub_dashboard_banner_visible (show_on_web, archived, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$sql[] = "CREATE TABLE IF NOT EXISTS hub_announcement (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  heading VARCHAR(255) NOT NULL,
  subheading VARCHAR(255) NULL,
  body_html MEDIUMTEXT NULL,
  image_url VARCHAR(255) NULL,
  link_url VARCHAR(255) NULL,
  link_label VARCHAR(100) NULL,
  show_from DATETIME NULL,
  show_to DATETIME NULL,
  sort INT NOT NULL DEFAULT 100,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_announcement_name (name),
  KEY idx_hub_announcement_window (show_on_web, archived, show_from, show_to),
  KEY idx_hub_announcement_sort (sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_announcement_user (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  dismissed_at DATETIME NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_announcement_user (announcement_id, user_id),
  KEY idx_hub_announcement_user_user (user_id),
  CONSTRAINT fk_hub_announcement_user_announcement FOREIGN KEY (announcement_id) REFERENCES hub_announcement(id) ON DELETE CASCADE,
  CONSTRAINT fk_hub_announcement_user_user FOREIGN KEY (user_id) REFERENCES hub_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_user_totp (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  secret VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  confirmed_at DATETIME NULL,
  last_counter BIGINT NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_user_totp_user (user_id),
  CONSTRAINT fk_hub_user_totp_user FOREIGN KEY (user_id) REFERENCES hub_user(id) ON DELETE CASCADE
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

$sql[] = "CREATE TABLE IF NOT EXISTS hub_magic_link (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  request_ip VARCHAR(64) NULL,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hub_magic_link_user FOREIGN KEY (user_id) REFERENCES hub_user(id) ON DELETE CASCADE,
  KEY idx_hub_magic_link_user (user_id),
  KEY idx_hub_magic_link_token (token_hash)
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

$sql[] = "CREATE TABLE IF NOT EXISTS hub_import_source (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_key VARCHAR(100) NOT NULL,
  name VARCHAR(200) NOT NULL,
  notes TEXT NULL,
  source_url TEXT NOT NULL,
  handler VARCHAR(100) NOT NULL DEFAULT 'generic_json',
  auth_type ENUM('none','basic') NOT NULL DEFAULT 'basic',
  auth_username VARCHAR(255) NULL,
  auth_password_ciphertext TEXT NULL,
  auth_password_nonce VARCHAR(255) NULL,
  verify_tls TINYINT(1) NOT NULL DEFAULT 1,
  timeout_seconds INT UNSIGNED NOT NULL DEFAULT 60,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_import_source_key (import_key),
  KEY idx_hub_import_source_handler (handler),
  KEY idx_hub_import_source_visible (show_on_web, archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_user_project_access (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  can_access TINYINT(1) NOT NULL DEFAULT 1,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_user_project_access (user_id, project_id),
  KEY idx_hub_user_project_access_project (project_id, can_access),
  CONSTRAINT fk_hub_user_project_access_user FOREIGN KEY (user_id) REFERENCES hub_user(id) ON DELETE CASCADE,
  CONSTRAINT fk_hub_user_project_access_project FOREIGN KEY (project_id) REFERENCES hub_project(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$sql[] = "CREATE TABLE IF NOT EXISTS hub_user_import_source_access (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  can_access TINYINT(1) NOT NULL DEFAULT 1,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_user_source_access (user_id, source_id),
  KEY idx_hub_user_source_access_source (source_id, can_access),
  CONSTRAINT fk_hub_user_source_access_user FOREIGN KEY (user_id) REFERENCES hub_user(id) ON DELETE CASCADE,
  CONSTRAINT fk_hub_user_source_access_source FOREIGN KEY (source_id) REFERENCES hub_import_source(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$sql[] = "CREATE TABLE IF NOT EXISTS hub_help_message (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  heading VARCHAR(255) NULL,
  context VARCHAR(30) NOT NULL DEFAULT 'info',
  icon VARCHAR(80) NOT NULL DEFAULT 'fa-solid fa-circle-info',
  message_html MEDIUMTEXT NOT NULL,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hub_help_message_name (name),
  KEY idx_hub_help_message_visible (show_on_web, archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS hub_faq (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section VARCHAR(50) NOT NULL DEFAULT 'user',
  page_key VARCHAR(100) NULL,
  question VARCHAR(255) NOT NULL,
  answer_html MEDIUMTEXT NOT NULL,
  sort INT NOT NULL DEFAULT 100,
  show_on_web TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_hub_faq_visible (show_on_web, archived, section, page_key, sort),
  KEY idx_hub_faq_page (page_key, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$sql[] = "CREATE TABLE IF NOT EXISTS hub_import_fetch (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NOT NULL,
  status ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
  http_status INT UNSIGNED NULL,
  content_type VARCHAR(255) NULL,
  bytes_downloaded INT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  processed_count INT UNSIGNED NOT NULL DEFAULT 0,
  skipped TINYINT(1) NOT NULL DEFAULT 0,
  error_text TEXT NULL,
  triggered_by INT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY idx_hub_import_fetch_source (source_id, id),
  KEY idx_hub_import_fetch_status (status),
  KEY idx_hub_import_fetch_sha (source_id, sha256),
  CONSTRAINT fk_hub_import_fetch_source FOREIGN KEY (source_id) REFERENCES hub_import_source(id) ON DELETE CASCADE,
  CONSTRAINT fk_hub_import_fetch_user FOREIGN KEY (triggered_by) REFERENCES hub_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

foreach ($sql as $statement) {
  exec_sql($pdo, $statement);
}

$migrations = [
  "ALTER TABLE hub_faq ADD COLUMN page_key VARCHAR(100) NULL AFTER section",
  "ALTER TABLE hub_faq ADD COLUMN show_on_web TINYINT(1) NOT NULL DEFAULT 1 AFTER sort",
  "ALTER TABLE hub_faq ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER show_on_web",
  "ALTER TABLE hub_faq ADD KEY idx_hub_faq_visible (show_on_web, archived, section, page_key, sort)",
  "ALTER TABLE hub_faq ADD KEY idx_hub_faq_page (page_key, sort)",
  "ALTER TABLE hub_help_message ADD COLUMN context VARCHAR(30) NOT NULL DEFAULT 'info' AFTER heading",
  "ALTER TABLE hub_help_message ADD COLUMN icon VARCHAR(80) NOT NULL DEFAULT 'fa-solid fa-circle-info' AFTER context",
  "ALTER TABLE hub_help_message MODIFY COLUMN icon VARCHAR(80) NOT NULL DEFAULT 'fa-solid fa-circle-info'",
  "ALTER TABLE hub_content_page ADD COLUMN slug VARCHAR(150) NULL AFTER page_key",
  "ALTER TABLE hub_content_page ADD COLUMN meta_title VARCHAR(255) NULL AFTER title",
  "ALTER TABLE hub_content_page ADD COLUMN meta_description VARCHAR(320) NULL AFTER meta_title",
  "ALTER TABLE hub_content_page ADD COLUMN canonical_url VARCHAR(255) NULL AFTER meta_description",
  "ALTER TABLE hub_content_page ADD COLUMN robots VARCHAR(50) NOT NULL DEFAULT 'index,follow' AFTER canonical_url",
  "ALTER TABLE hub_content_page ADD UNIQUE KEY uq_hub_content_page_slug (slug)",
  "ALTER TABLE hub_admin_action ADD COLUMN assigned_user_id INT UNSIGNED NULL AFTER entity_id",
  "ALTER TABLE hub_admin_action ADD KEY idx_hub_admin_action_assigned (assigned_user_id, status)",
  "ALTER TABLE hub_user ADD COLUMN job_title VARCHAR(200) NULL AFTER display_name",
  "ALTER TABLE hub_user ADD COLUMN phone VARCHAR(50) NULL AFTER job_title",
  "ALTER TABLE hub_user ADD COLUMN linkedin VARCHAR(255) NULL AFTER phone",
  "ALTER TABLE hub_user MODIFY COLUMN linkedin VARCHAR(255) NULL",
  "ALTER TABLE hub_user ADD COLUMN image VARCHAR(255) NULL AFTER linkedin",
  "ALTER TABLE hub_user MODIFY COLUMN image VARCHAR(255) NULL",
  "ALTER TABLE hub_user MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'user'",
  "ALTER TABLE hub_user ADD KEY idx_hub_user_role (role)",
  "ALTER TABLE hub_user_role ADD COLUMN icon_class VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-user' AFTER description",
  "ALTER TABLE hub_customer ADD COLUMN address_line1 VARCHAR(255) NULL AFTER contact_phone",
  "ALTER TABLE hub_customer ADD COLUMN address_line2 VARCHAR(255) NULL AFTER address_line1",
  "ALTER TABLE hub_customer ADD COLUMN address_line3 VARCHAR(255) NULL AFTER address_line2",
  "ALTER TABLE hub_customer ADD COLUMN address_city VARCHAR(120) NULL AFTER address_line3",
  "ALTER TABLE hub_customer ADD COLUMN address_county VARCHAR(120) NULL AFTER address_city",
  "ALTER TABLE hub_customer ADD COLUMN address_postcode VARCHAR(40) NULL AFTER address_county",
  "ALTER TABLE hub_customer ADD COLUMN address_country VARCHAR(120) NULL AFTER address_postcode",
  "ALTER TABLE hub_customer ADD COLUMN key_contact_user_id INT UNSIGNED NULL AFTER address_country",
  "ALTER TABLE hub_customer ADD COLUMN customer_type ENUM('customer','prospect') NOT NULL DEFAULT 'customer' AFTER key_contact_user_id",
  "ALTER TABLE hub_customer ADD KEY idx_hub_customer_type (customer_type, archived)",
  "ALTER TABLE hub_customer ADD KEY idx_hub_customer_key_contact (key_contact_user_id)",
  "ALTER TABLE hub_customer ADD CONSTRAINT fk_hub_customer_key_contact_user FOREIGN KEY (key_contact_user_id) REFERENCES hub_user(id) ON DELETE SET NULL",
  "ALTER TABLE hub_report_column ADD COLUMN is_link TINYINT(1) NOT NULL DEFAULT 0 AFTER at_a_glance",
  "ALTER TABLE hub_report_column ADD COLUMN is_report TINYINT(1) NOT NULL DEFAULT 0 AFTER is_link",
  "ALTER TABLE hub_report_column ADD COLUMN is_table TINYINT(1) NOT NULL DEFAULT 1 AFTER is_report",
  "ALTER TABLE hub_report_column ADD COLUMN is_export TINYINT(1) NOT NULL DEFAULT 1 AFTER is_table",
];

foreach ($migrations as $statement) {
  try {
    $pdo->exec($statement);
  } catch (PDOException $e) {
    $message = $e->getMessage();
    $alreadyApplied = (
      $e->getCode() === '42S21'
      || stripos($message, 'Duplicate column') !== false
      || stripos($message, 'Duplicate key name') !== false
      || stripos($message, 'Duplicate foreign key constraint name') !== false
      || stripos($message, 'Duplicate key on write or update') !== false
      || stripos($message, 'already exists') !== false
    );
    if (!$alreadyApplied) {
      throw $e;
    }
  }
}

if (install_table_exists($pdo, 'hub_faq')) {
  $loginTimingQuestion = 'How long do I have to sign in?';
  $loginTimingAnswer = '<p>Email sign-in links last for 15 minutes. Open the link, then select Continue signing in.</p><p>Password reset links last for 30 minutes, and security codes sent by email last for 10 minutes. Codes in an authenticator app change every 30 seconds, so always use the newest code shown.</p><p>These times are the same wherever you are in the world. If a link or code has run out, simply request a new one.</p>';
  $stmtLoginTimingFaq = $pdo->prepare(
    'INSERT INTO hub_faq (section, page_key, question, answer_html, sort, show_on_web, archived, created, modified)
     SELECT :section, NULL, :question, :answer_html, :sort, 1, 0, NOW(), NOW()
     WHERE NOT EXISTS (SELECT 1 FROM hub_faq WHERE question = :existing_question AND archived = 0)'
  );
  $stmtLoginTimingFaq->execute([
    ':section' => 'user',
    ':question' => $loginTimingQuestion,
    ':answer_html' => $loginTimingAnswer,
    ':sort' => 50,
    ':existing_question' => $loginTimingQuestion,
  ]);
}

$hubUserRoles = [
  ['role_key' => 'user', 'label' => 'User', 'description' => 'Customer user with access to assigned-company front-end reports.', 'icon_class' => 'fa-solid fa-user', 'rank' => 10],
  ['role_key' => 'manager', 'label' => 'Manager', 'description' => 'Company-level power user for assigned-company management actions.', 'icon_class' => 'fa-solid fa-user-gear', 'rank' => 20],
  ['role_key' => 'admin', 'label' => 'Admin', 'description' => 'Internal admin user with operational admin access.', 'icon_class' => 'fa-solid fa-shield-halved', 'rank' => 30],
  ['role_key' => 'super_admin', 'label' => 'Super Admin', 'description' => 'Full business admin with high-level administration access.', 'icon_class' => 'fa-solid fa-crown', 'rank' => 40],
  ['role_key' => 'developer', 'label' => 'Developer', 'description' => 'Technical user with developer and diagnostic access.', 'icon_class' => 'fa-solid fa-code', 'rank' => 50],
];

if (hub_table_exists('hub_dashboard_banner')) {
  $stmtBanner = $pdo->prepare(
    'INSERT INTO hub_dashboard_banner (name, image, alt_text, sort, show_on_web, archived, created, modified)
     VALUES (:name, :image, :alt_text, 100, 1, 0, NOW(), NOW())
     ON DUPLICATE KEY UPDATE name = name'
  );
  $stmtBanner->execute([
    ':name' => 'default-dashboard-banner',
    ':image' => '/filestore/images/content/lg/hubrxsbanner-3000-500.webp',
    ':alt_text' => 'RxSource Hub',
  ]);
}
if (hub_table_exists('hub_content_page')) {
  $contentPages = [
    ['privacy_policy', 'privacy-policy', 'Privacy Policy', 'How RxSource handles personal information for Hub users and website visitors.'],
    ['cookie_policy', 'cookie-policy', 'Cookie Policy', 'Information about cookies and similar technologies used by the Hub.'],
    ['terms', 'terms', 'Terms of Use', 'General terms for accessing and using the RxSource Hub.'],
    ['accessibility', 'accessibility', 'Accessibility', 'Accessibility information and contact route for support.'],
  ];
  $stmtContent = $pdo->prepare(
    'INSERT INTO hub_content_page (page_key, slug, title, meta_title, meta_description, robots, body, published, updated_at, created, modified)
     VALUES (:page_key, :slug, :title, :meta_title, :meta_description, ' . $pdo->quote('index,follow') . ', :body, 1, NOW(), NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      slug = COALESCE(slug, VALUES(slug)),
      meta_title = COALESCE(meta_title, VALUES(meta_title)),
      meta_description = COALESCE(meta_description, VALUES(meta_description)),
      robots = COALESCE(NULLIF(robots, \'\'), VALUES(robots))'
  );
  foreach ($contentPages as $page) {
    $stmtContent->execute([
      ':page_key' => $page[0],
      ':slug' => $page[1],
      ':title' => $page[2],
      ':meta_title' => $page[2],
      ':meta_description' => $page[3],
      ':body' => $page[3],
    ]);
  }
}
if (hub_table_exists('hub_user_role')) {
  $stmtUserRole = $pdo->prepare(
    'INSERT INTO hub_user_role
      (role_key, label, description, icon_class, rank, can_login, archived, created, modified)
     VALUES
      (:role_key, :label, :description, :icon_class, :rank, 1, 0, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      label = VALUES(label),
      description = VALUES(description),
      icon_class = VALUES(icon_class),
      rank = VALUES(rank),
      can_login = 1,
      archived = 0,
      modified = NOW()'
  );
  foreach ($hubUserRoles as $role) {
    $stmtUserRole->execute([
      ':role_key' => $role['role_key'],
      ':label' => $role['label'],
      ':description' => $role['description'],
      ':icon_class' => $role['icon_class'],
      ':rank' => $role['rank'],
    ]);
  }
}

if (install_table_exists($pdo, 'hub_dashboard_button')) {
  $dashboardButtons = [
    ['shipping', 'Shipping Report', '/client-portal-shipping.php', 'portal-dashboard-tile-shipping', '', 'shipping', 1, 0, 0, 10],
    ['inventory', 'Inventory Report', '/client-portal-inventory.php', 'portal-dashboard-tile-inventory', '', 'inventory', 1, 0, 0, 20],
    ['kpi', 'KPIs/Metric', '/client-portal-kpis.php', 'portal-dashboard-tile-kpi', '', '', 1, 0, 1, 30],
    ['artifacts', 'Project Artifacts', '/client-portal-project-artifacts.php', 'portal-dashboard-tile-artifacts', '', '', 1, 0, 1, 40],
    ['proposals', 'Proposals, Change Orders and Contracts', '/client-portal-proposals.php', 'portal-dashboard-tile-proposals', '', '', 0, 1, 0, 50],
    ['testimonials', 'Testimonials', '/testimonials.php', 'portal-dashboard-tile-testimonials', '', '', 0, 1, 1, 60],
    ['marketing', 'Marketing', '/client-portal-marketing.php', 'portal-dashboard-tile-marketing', '', '', 0, 1, 1, 70],
  ];
  $stmtDashboardButton = $pdo->prepare(
    'INSERT INTO hub_dashboard_button
      (button_key, title, href, css_class, image_url, count_key, show_customer, show_prospect, show_staff, show_on_web, sort, archived, created, modified)
     VALUES
      (:button_key, :title, :href, :css_class, :image_url, :count_key, :show_customer, :show_prospect, :show_staff, 1, :sort, 0, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      title = VALUES(title),
      href = VALUES(href),
      css_class = VALUES(css_class),
      image_url = VALUES(image_url),
      count_key = VALUES(count_key),
      show_customer = VALUES(show_customer),
      show_prospect = VALUES(show_prospect),
      show_staff = VALUES(show_staff),
      show_on_web = 1,
      sort = VALUES(sort),
      archived = 0,
      modified = NOW()'
  );
  foreach ($dashboardButtons as $button) {
    $stmtDashboardButton->execute([
      ':button_key' => $button[0],
      ':title' => $button[1],
      ':href' => $button[2],
      ':css_class' => $button[3],
      ':image_url' => $button[4],
      ':count_key' => $button[5],
      ':show_customer' => $button[6],
      ':show_prospect' => $button[7],
      ':show_staff' => $button[8],
      ':sort' => $button[9],
    ]);
  }
}

if (install_table_exists($pdo, 'hub_report_column')) {
  $reportColumns = [
    ['shipping', 'order_nbr', 'OrderNbr', 'order_nbr', 'Order #', 'text', 'search', 0, 100],
    ['shipping', 'description', 'Description', 'description', 'Description', 'text', 'search', 0, 200],
    ['shipping', 'status', 'Status', 'status', 'Status', 'text', 'select', 1, 300],
    ['shipping', 'requested_on', 'RequestedOn', 'requested_on', 'Requested On', 'date', 'search', 0, 400],
    ['shipping', 'shipment_date', 'ShipmentDate', 'shipment_date', 'Shipment Date', 'date', 'search', 0, 500],
    ['shipping', 'line_description', 'LineDescription', 'line_description', 'Product Details', 'text', 'search', 0, 600],
    ['shipping', 'ship_via', 'ShipVia', 'ship_via', 'Courier', 'text', 'select', 0, 700],
    ['shipping', 'tracking_number', 'TrackingNumber', 'tracking_number', 'Tracking Number', 'text', 'search', 0, 800],
    ['shipping', 'lot_serial_nbr', 'LotSerialNbr', 'lot_serial_nbr', 'Lot #', 'text', 'search', 0, 900],
    ['shipping', 'quantity', 'Quantity', 'quantity', 'Quantity', 'number', 'search', 0, 1000],
    ['shipping', 'branch_name', 'BranchName', 'branch_name', 'Branch Name', 'text', 'select', 0, 1100],
    ['inventory', 'InventoryID', 'InventoryID', 'InventoryID', 'Inventory ID', 'text', 'select', 0, 100],
    ['inventory', 'Description', 'Description', 'Description', 'Description', 'text', 'search', 0, 200],
    ['inventory', 'LotSerialNbr', 'LotSerialNbr', 'LotSerialNbr', 'Lot #', 'text', 'search', 0, 300],
    ['inventory', 'ExpiryDate', 'ExpiryDate', 'ExpiryDate', 'Expiry Date', 'date', 'search', 0, 400],
    ['inventory', 'QtyOnHand', 'QtyOnHand', 'QtyOnHand', 'Quantity On Hand', 'number', 'search', 0, 500],
    ['inventory', 'QtyAvailable', 'QtyAvailable', 'QtyAvailable', 'Quantity Available', 'number', 'search', 0, 600],
    ['inventory', 'WarehouseID', 'WarehouseID', 'WarehouseID', 'Warehouse Location', 'text', 'select', 1, 700],
  ];
  $stmtReportColumn = $pdo->prepare(
    'INSERT INTO hub_report_column
      (report_key, column_key, source_field, data_field, heading, display_type, filter_type, show_on_web, at_a_glance, sort, archived, created, modified)
     VALUES
      (:report_key, :column_key, :source_field, :data_field, :heading, :display_type, :filter_type, 1, :at_a_glance, :sort, 0, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      source_field = VALUES(source_field),
      data_field = VALUES(data_field),
      heading = VALUES(heading),
      display_type = VALUES(display_type),
      filter_type = VALUES(filter_type),
      show_on_web = 1,
      at_a_glance = VALUES(at_a_glance),
      sort = VALUES(sort),
      archived = 0,
      modified = NOW()'
  );
  foreach ($reportColumns as $column) {
    $stmtReportColumn->execute([
      ':report_key' => $column[0],
      ':column_key' => $column[1],
      ':source_field' => $column[2],
      ':data_field' => $column[3],
      ':heading' => $column[4],
      ':display_type' => $column[5],
      ':filter_type' => $column[6],
      ':at_a_glance' => $column[7],
      ':sort' => $column[8],
    ]);
  }
}

if (install_table_exists($pdo, 'hub_report_value_colour')) {
  $reportValueColours = [
    ['inventory', 'WarehouseID', 'Ireland', 'Ireland', '#0ec27a', 10, 1],
    ['inventory', 'WarehouseID', 'Canada', 'Canada', '#ed1b2f', 20, 1],
    ['inventory', 'WarehouseID', 'USA', 'USA', '#1f9acb', 30, 1],
    ['inventory', 'WarehouseID', 'WDWHSE', 'WDWHSE', '#14b8a6', 40, 1],
  ];
  $stmtReportValueColour = $pdo->prepare(
    'INSERT INTO hub_report_value_colour
      (report_key, column_key, value_key, display_label, colour, sort, show_when_zero, show_on_web, archived, created, modified)
     VALUES
      (:report_key, :column_key, :value_key, :display_label, :colour, :sort, :show_when_zero, 1, 0, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      display_label = VALUES(display_label),
      colour = VALUES(colour),
      sort = VALUES(sort),
      show_when_zero = VALUES(show_when_zero),
      show_on_web = 1,
      archived = 0,
      modified = NOW()'
  );
  foreach ($reportValueColours as $colour) {
    $stmtReportValueColour->execute([
      ':report_key' => $colour[0],
      ':column_key' => $colour[1],
      ':value_key' => $colour[2],
      ':display_label' => $colour[3],
      ':colour' => $colour[4],
      ':sort' => $colour[5],
      ':show_when_zero' => $colour[6],
    ]);
  }
}

if (install_table_exists($pdo, 'hub_testimonial')) {
  $sampleTestimonials = [
    [
      'Arcadia Clinical Supply',
      'Emma Hart',
      'Director of Clinical Operations',
      'testimonial-arcadia-clinical-supply.png',
      'The RxSource Hub gives our team a much clearer view of project activity and makes it easier to find the information we need without chasing updates across different channels.',
      10,
    ],
    [
      'Northbridge BioPharma',
      'Daniel Reyes',
      'Senior Project Lead',
      'testimonial-northbridge-biopharma.png',
      'Having key reports, contacts, and supporting project information in one place has helped our internal teams stay aligned and respond more quickly when timelines shift.',
      20,
    ],
    [
      'Helix Trial Partners',
      'Priya Shah',
      'VP Client Programmes',
      'testimonial-helix-trial-partners.png',
      'The portal feels focused and practical. It supports the working relationship by keeping important detail visible, organised, and easy for our stakeholders to review.',
      30,
    ],
  ];
  $stmtExistingTestimonials = $pdo->query('SELECT COUNT(*) FROM hub_testimonial');
  if ((int) ($stmtExistingTestimonials ? $stmtExistingTestimonials->fetchColumn() : 0) === 0) {
    $stmtTestimonial = $pdo->prepare(
      'INSERT INTO hub_testimonial
        (client_company_name, contact_name, job_title, client_logo, testimonial_text, show_on_web, sort, archived, created, modified)
       VALUES
        (:company, :contact, :job_title, :client_logo, :testimonial_text, 1, :sort, 0, NOW(), NOW())'
    );
    foreach ($sampleTestimonials as $testimonial) {
      $stmtTestimonial->execute([
        ':company' => $testimonial[0],
        ':contact' => $testimonial[1],
        ':job_title' => $testimonial[2],
        ':client_logo' => $testimonial[3],
        ':testimonial_text' => $testimonial[4],
        ':sort' => $testimonial[5],
      ]);
    }
  }
}

$keyInfoRoles = [
  [
    'role_key' => 'project_management_1',
    'label' => 'Project Management 1',
    'section_label' => 'Your Rx Project Management Team',
    'default_sort' => 10,
  ],
  [
    'role_key' => 'project_management_2',
    'label' => 'Project Management 2',
    'section_label' => 'Your Rx Project Management Team',
    'default_sort' => 20,
  ],
  [
    'role_key' => 'bd_representative',
    'label' => 'BD Representative',
    'section_label' => 'Your Rx BD Representative',
    'default_sort' => 30,
  ],
  [
    'role_key' => 'sponsor',
    'label' => 'Sponsor',
    'section_label' => 'Your Rx Sponsor',
    'default_sort' => 40,
  ],
  [
    'role_key' => 'escalation',
    'label' => 'Escalation',
    'section_label' => 'Escalation Contact',
    'default_sort' => 50,
  ],
];

if (hub_table_exists('hub_key_info_role')) {
  $stmtRole = $pdo->prepare(
    'INSERT INTO hub_key_info_role
      (role_key, label, section_label, default_sort, show_on_web, archived, created, modified)
     VALUES
      (:role_key, :label, :section_label, :default_sort, 1, 0, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      label = VALUES(label),
      section_label = VALUES(section_label),
      default_sort = VALUES(default_sort),
      show_on_web = 1,
      modified = NOW()'
  );
  foreach ($keyInfoRoles as $role) {
    $stmtRole->execute([
      ':role_key' => $role['role_key'],
      ':label' => $role['label'],
      ':section_label' => $role['section_label'],
      ':default_sort' => $role['default_sort'],
    ]);
  }
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
