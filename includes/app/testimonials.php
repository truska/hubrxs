<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/images.php';

function hub_testimonials_table_ready(): bool {
  return hub_table_exists('hub_testimonial');
}

function hub_testimonial_logo_base_name(string $companyName): string {
  $base = hub_image_slug($companyName);
  return $base !== '' ? 'testimonial-' . $base : 'testimonial-logo';
}

function hub_testimonial_logo_src(?string $imageValue, string $size = 'sm'): string {
  $imageValue = trim((string) $imageValue);
  if ($imageValue === '') {
    return '';
  }
  if (preg_match('/^https?:\/\//i', $imageValue) || str_starts_with($imageValue, '/')) {
    return $imageValue;
  }
  return hub_image_public_path('content', $size, $imageValue);
}

function hub_testimonials_all(bool $includeArchived = true): array {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_testimonials_table_ready()) {
    return [];
  }

  $where = $includeArchived ? '1=1' : 'archived = 0';
  $stmt = $pdo->query(
    'SELECT *
     FROM hub_testimonial
     WHERE ' . $where . '
     ORDER BY sort ASC, created DESC, id ASC'
  );
  return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function hub_testimonial_get(int $id): ?array {
  global $pdo, $DB_OK;

  if ($id <= 0 || !$DB_OK || !($pdo instanceof PDO) || !hub_testimonials_table_ready()) {
    return null;
  }

  $stmt = $pdo->prepare('SELECT * FROM hub_testimonial WHERE id = :id LIMIT 1');
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function hub_testimonials_published(): array {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_testimonials_table_ready()) {
    return [];
  }

  $stmt = $pdo->query(
    'SELECT *
     FROM hub_testimonial
     WHERE archived = 0
       AND show_on_web = 1
     ORDER BY sort ASC, created DESC, id ASC'
  );
  return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
