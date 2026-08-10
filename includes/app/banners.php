<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/images.php';

function hub_dashboard_banner_table_ready(): bool {
  return hub_table_exists('hub_dashboard_banner');
}

function hub_dashboard_banner_base_name(string $name): string {
  $name = trim($name);
  if ($name === '') {
    $name = 'dashboard-banner';
  }
  return 'banner-' . hub_image_slug($name);
}

function hub_dashboard_banner_image_src(?string $imageValue, string $size = 'lg'): string {
  $imageValue = trim((string) $imageValue);
  if ($imageValue === '') {
    return '';
  }
  if (preg_match('#^https?://#i', $imageValue)) {
    return $imageValue;
  }
  if (str_starts_with($imageValue, '/') && !str_starts_with($imageValue, '/filestore/images/content/')) {
    return $imageValue;
  }

  $filename = basename($imageValue);
  if ($filename === '' || $filename === '.' || $filename === '..') {
    return $imageValue;
  }

  $webpFilename = preg_replace('/\.[^.]+$/', '.webp', $filename) ?: ($filename . '.webp');
  $webpPath = hub_image_destination_dir('content', $size) . '/' . $webpFilename;
  if (is_file($webpPath)) {
    return hub_image_public_path('content', $size, $webpFilename);
  }

  $sourcePath = hub_image_destination_dir('content', $size) . '/' . $filename;
  if (is_file($sourcePath)) {
    return hub_image_public_path('content', $size, $filename);
  }

  return str_starts_with($imageValue, '/') ? $imageValue : hub_image_public_path('content', $size, $filename);
}

function hub_dashboard_banner_active(): array {
  global $pdo, $DB_OK;

  $fallback = [
    'name' => 'Default Dashboard Banner',
    'image' => '/filestore/images/content/lg/hubrxsbanner-3000-500.webp',
    'alt_text' => 'RxSource Hub',
    'sort' => 100,
    'show_on_web' => 1,
  ];

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_dashboard_banner_table_ready()) {
    return $fallback;
  }

  $stmt = $pdo->query(
    'SELECT *
     FROM hub_dashboard_banner
     WHERE show_on_web = 1
       AND archived = 0
       AND image <> ""
     ORDER BY sort ASC, id ASC
     LIMIT 1'
  );
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  return $row ?: $fallback;
}

function hub_dashboard_banners_all(): array {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_dashboard_banner_table_ready()) {
    return [];
  }

  $stmt = $pdo->query(
    'SELECT *
     FROM hub_dashboard_banner
     ORDER BY archived ASC, show_on_web DESC, sort ASC, id DESC'
  );
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hub_dashboard_banner_get(int $id): ?array {
  global $pdo, $DB_OK;

  if ($id <= 0 || !$DB_OK || !($pdo instanceof PDO) || !hub_dashboard_banner_table_ready()) {
    return null;
  }

  $stmt = $pdo->prepare('SELECT * FROM hub_dashboard_banner WHERE id = :id LIMIT 1');
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  return $row ?: null;
}
