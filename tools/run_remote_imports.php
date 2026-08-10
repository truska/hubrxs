<?php
require_once __DIR__ . '/../includes/app/imports/remote_sources.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
  hub_require_admin();
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hub_verify_csrf($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo "Invalid request.";
    exit;
  }
}

$force = $isCli
  ? in_array('--force', $argv, true)
  : !empty($_POST['force']);

$sourceKey = null;
$sourceId = null;

if ($isCli) {
  foreach ($argv as $arg) {
    if (str_starts_with($arg, '--source=')) {
      $sourceKey = substr($arg, 9);
    } elseif (str_starts_with($arg, '--id=')) {
      $sourceId = (int) substr($arg, 5);
    }
  }
} else {
  $sourceId = (int) ($_POST['source_id'] ?? 0);
}

$triggeredBy = $isCli ? null : (hub_current_user()['id'] ?? null);
$results = [];

if ($sourceId) {
  $source = hub_import_source_by_id($sourceId);
  $results[] = [
    'source' => $source,
    'result' => $source ? hub_import_run_source($sourceId, $triggeredBy, $force) : ['ok' => false, 'message' => 'Import source not found.'],
  ];
} elseif ($sourceKey) {
  $source = hub_import_source_by_key($sourceKey);
  $results[] = [
    'source' => $source,
    'result' => $source ? hub_import_run_source((int) $source['id'], $triggeredBy, $force) : ['ok' => false, 'message' => 'Import source not found.'],
  ];
} else {
  $results = hub_import_run_all($triggeredBy, $force);
}

$auditResults = [];
foreach ($results as $entry) {
  $auditResults[] = [
    'source_id' => isset($entry['source']['id']) ? (int) $entry['source']['id'] : null,
    'source_name' => $entry['source']['name'] ?? null,
    'ok' => !empty($entry['result']['ok']),
    'message' => $entry['result']['message'] ?? null,
  ];
}
hub_log_user_action([
  'user' => $isCli ? null : hub_current_user(),
  'action_key' => 'remote_import_run',
  'action_title' => 'Remote import run',
  'table_name' => 'hub_import_fetch',
  'details' => ['trigger' => $isCli ? 'cli' : 'web', 'force' => $force, 'source_id' => $sourceId, 'source_key' => $sourceKey, 'results' => $auditResults],
]);

if ($isCli) {
  $failed = 0;
  foreach ($results as $entry) {
    $sourceName = $entry['source']['name'] ?? 'Unknown source';
    $result = $entry['result'];
    echo ($result['ok'] ? 'OK' : 'FAIL') . ": {$sourceName}: {$result['message']}\n";
    if (empty($result['ok'])) {
      $failed++;
    }
  }
  exit($failed > 0 ? 1 : 0);
}

foreach ($results as $entry) {
  $sourceName = $entry['source']['name'] ?? 'Unknown source';
  $result = $entry['result'];
  hub_flash($result['ok'] ? 'success' : 'error', $sourceName . ': ' . $result['message']);
}

hub_redirect('/admin/import-sources.php');
