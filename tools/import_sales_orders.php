<?php
require_once __DIR__ . '/../includes/app/import_sales_orders.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
  hub_require_admin();
}

$source = null;
$originalName = null;

if ($isCli) {
  $source = $argv[1] ?? null;
} else {
  $source = $_GET['file'] ?? null;
}

if (!$source) {
  // Default to most recent file in private/imports.
  $pattern = __DIR__ . '/../../private/imports/*.json';
  $files = glob($pattern);
  rsort($files, SORT_STRING);
  $source = $files[0] ?? null;
}

if (!$source) {
  $msg = 'No import file found. Provide a path or upload to private/imports first.';
  if ($isCli) {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
  }
  http_response_code(400);
  echo hub_h($msg);
  exit;
}

if (!is_file($source)) {
  $msg = 'File not found: ' . $source;
  if ($isCli) {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
  }
  http_response_code(404);
  echo hub_h($msg);
  exit;
}

$originalName = basename($source);
$userId = $isCli ? null : (hub_current_user()['id'] ?? null);
$result = hub_import_sales_order_file($source, $originalName, $userId);

if ($isCli) {
  echo ($result['ok'] ? 'OK: ' : 'FAIL: ') . $result['message'] . PHP_EOL;
  exit($result['ok'] ? 0 : 1);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Import Sales Orders</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand">Admin</p>
      <h1>Sales Order Import</h1>
      <p>Source file: <code><?php echo hub_h($source); ?></code></p>
      <div class="alert <?php echo $result['ok'] ? 'success' : 'error'; ?>">
        <?php echo hub_h($result['message']); ?>
      </div>
      <?php if (!empty($result['import_id'])): ?>
        <p class="muted">Import ID: <?php echo (int) $result['import_id']; ?> | SHA256: <?php echo hub_h($result['sha256'] ?? ''); ?></p>
      <?php endif; ?>
      <div class="links">
        <a href="/dashboard.php">Back to dashboard</a>
      </div>
    </div>
  </div>
</body>
</html>
