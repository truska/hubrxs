<?php
require_once __DIR__ . '/auth.php';

function hub_general_content_ready(): bool { return hub_table_exists('hub_general_content'); }
function hub_general_content_defaults(): array {
  return [[
    'id' => 0, 'content_key' => 'dashboard_intro', 'title' => 'Introduction to RX Hub',
    'body' => "The Hub to support your Business\n\nRX Hub brings the key areas of your client experience together in one place, giving your team a clear route into reports, project information, support details, and the tools we are building around your day-to-day work with RxSource.\n\nThis space will continue to grow as more live data and controls are connected, helping keep important updates visible, making current information easier to find, and supporting better communication across your business.",
    'sort' => 100, 'published' => 1,
  ]];
}
function hub_general_content_all(bool $publishedOnly = false): array {
  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_general_content_ready()) return hub_general_content_defaults();
  $where = $publishedOnly ? 'WHERE published = 1 AND archived = 0' : 'WHERE archived = 0';
  $stmt = $pdo->query('SELECT * FROM hub_general_content ' . $where . ' ORDER BY sort ASC, id ASC');
  $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  return $rows ?: hub_general_content_defaults();
}
