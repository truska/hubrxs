<?php
require_once __DIR__ . '/../includes/app/imports/remote_sources.php';

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  echo "CLI only.";
  exit;
}

function env_value(string $name, string $default = ''): string {
  $value = getenv($name);
  return $value === false ? $default : (string) $value;
}

$input = [
  'import_key' => env_value('IMPORT_KEY', $argv[1] ?? ''),
  'name' => env_value('IMPORT_NAME', $argv[2] ?? ''),
  'source_url' => env_value('IMPORT_URL', $argv[3] ?? ''),
  'handler' => env_value('IMPORT_HANDLER', $argv[4] ?? 'generic_json'),
  'auth_type' => env_value('IMPORT_AUTH_TYPE', 'basic'),
  'auth_username' => env_value('IMPORT_USERNAME', ''),
  'auth_password' => env_value('IMPORT_PASSWORD', ''),
  'notes' => env_value('IMPORT_NOTES', ''),
  'show_on_web' => env_value('IMPORT_SHOW_ON_WEB', '1') !== '0',
  'archived' => env_value('IMPORT_ARCHIVED', '0') === '1',
  'timeout_seconds' => (int) env_value('IMPORT_TIMEOUT', '60'),
];

if ($input['import_key'] === '' || $input['name'] === '' || $input['source_url'] === '') {
  fwrite(STDERR, "Usage: IMPORT_USERNAME=... IMPORT_PASSWORD=... php tools/upsert_import_source.php <key> <name> <url> [handler]\n");
  exit(1);
}

try {
  $id = hub_import_upsert_source($input);
  echo "OK: import source {$input['import_key']} saved as ID {$id}.\n";
} catch (Throwable $e) {
  fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
  exit(1);
}
