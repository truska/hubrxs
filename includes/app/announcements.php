<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/images.php';

function hub_active_announcements_for_user(int $userId): array {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_announcement')) {
    return [];
  }

  $stmt = $pdo->prepare(
    'SELECT a.*
     FROM hub_announcement a
     LEFT JOIN hub_announcement_user au
       ON au.announcement_id = a.id
      AND au.user_id = :user_id
      AND au.dismissed_at IS NOT NULL
     WHERE a.show_on_web = 1
       AND a.archived = 0
       AND au.id IS NULL
       AND (a.show_from IS NULL OR a.show_from <= NOW())
       AND (a.show_to IS NULL OR a.show_to >= NOW())
     ORDER BY a.sort ASC, a.show_from ASC, a.id ASC
     LIMIT 1'
  );
  $stmt->execute([':user_id' => $userId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hub_dismiss_announcement(int $announcementId, int $userId): bool {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_announcement_user')) {
    return false;
  }

  $stmt = $pdo->prepare(
    'INSERT INTO hub_announcement_user (announcement_id, user_id, dismissed_at, created)
     VALUES (:announcement_id, :user_id, NOW(), NOW())
     ON DUPLICATE KEY UPDATE dismissed_at = NOW()'
  );
  return $stmt->execute([
    ':announcement_id' => $announcementId,
    ':user_id' => $userId,
  ]);
}

function hub_dismissed_announcements_for_user(int $userId): array {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_announcement') || !hub_table_exists('hub_announcement_user')) {
    return [];
  }

  $stmt = $pdo->prepare(
    'SELECT a.*
     FROM hub_announcement a
     INNER JOIN hub_announcement_user au
       ON au.announcement_id = a.id
      AND au.user_id = :user_id
      AND au.dismissed_at IS NOT NULL
     WHERE a.show_on_web = 1
       AND a.archived = 0
       AND (a.show_from IS NULL OR a.show_from <= NOW())
       AND (a.show_to IS NULL OR a.show_to >= NOW())
     ORDER BY a.sort ASC, a.show_from ASC, a.id ASC
     LIMIT 1'
  );
  $stmt->execute([':user_id' => $userId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hub_reveal_announcement(int $announcementId, int $userId): bool {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_announcement_user')) {
    return false;
  }

  $stmt = $pdo->prepare(
    'DELETE FROM hub_announcement_user
     WHERE announcement_id = :announcement_id
       AND user_id = :user_id
     LIMIT 1'
  );
  return $stmt->execute([
    ':announcement_id' => $announcementId,
    ':user_id' => $userId,
  ]);
}

function hub_announcements_all(): array {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_announcement')) {
    return [];
  }

  $stmt = $pdo->query(
    'SELECT *
     FROM hub_announcement
     ORDER BY archived ASC, show_on_web DESC, sort ASC, COALESCE(show_from, created) DESC, id DESC'
  );
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hub_announcement_get(int $id): ?array {
  global $pdo, $DB_OK;

  if ($id <= 0 || !$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_announcement')) {
    return null;
  }

  $stmt = $pdo->prepare('SELECT * FROM hub_announcement WHERE id = :id LIMIT 1');
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  return $row ?: null;
}

function hub_announcement_render_html(?string $body): string {
  return hub_content_sanitize_html((string) $body);
}
function hub_announcement_image_base_name(string $announcementName): string {
  $announcementName = trim($announcementName);
  if ($announcementName === '') {
    $announcementName = 'announcement';
  }
  return 'announcement_' . hub_image_slug($announcementName);
}

function hub_announcement_image_src(?string $imageValue, string $size = 'sm'): string {
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