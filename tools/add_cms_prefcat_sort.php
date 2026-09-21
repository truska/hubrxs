<?php
// One-time, idempotent migration for the legacy CMS preference categories.
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}
require_once __DIR__ . '/../includes/app/bootstrap.php';

if (!$DB_OK || !($pdo instanceof PDO)) {
  fwrite(STDERR, "Database not available.\n");
  exit(1);
}
if (!hub_table_exists('cms_prefCat')) {
  fwrite(STDERR, "cms_prefCat table not found.\n");
  exit(1);
}
try {
  $pdo->exec('ALTER TABLE `cms_prefCat` ADD COLUMN `sort` INT NOT NULL DEFAULT 100 AFTER `id`');
  echo "Added cms_prefCat.sort.\n";
} catch (PDOException $e) {
  if ($e->getCode() === '42S21' || stripos($e->getMessage(), 'Duplicate column') !== false) {
    echo "cms_prefCat.sort already exists.\n";
  } else {
    throw $e;
  }
}
