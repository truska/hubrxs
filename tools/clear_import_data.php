<?php
require_once __DIR__ . '/../includes/app/auth.php';
hub_require_admin();

global $pdo, $DB_OK;

if (!$DB_OK || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo 'Database not available.';
  exit;
}

$confirmed = false;
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    http_response_code(403);
    $messages[] = 'Your session expired. Please try again.';
  } elseif (($_POST['confirm'] ?? '') !== 'DELETE') {
    http_response_code(400);
    $messages[] = 'Confirmation was not valid.';
  } else {
    $confirmed = true;
  $tables = [
    'hub_inventory_live',
    'hub_inventory_processed',
    'hub_inventory_raw',
    'hub_so_live',
    'hub_so_processed',
    'hub_so_raw',
    'hub_import_fetch',
  ];

  foreach ($tables as $table) {
    if (!hub_table_exists($table)) {
      $messages[] = $table . ': not found';
      continue;
    }
    try {
      $pdo->exec('DELETE FROM `' . str_replace('`', '', $table) . '`');
      $messages[] = $table . ': cleared';
    } catch (PDOException $e) {
      $messages[] = $table . ': ' . $e->getMessage();
    }
  }
    hub_log_user_action([
      'user' => hub_current_user(),
      'action_key' => 'import_data_cleared',
      'action_title' => 'Import data cleared',
      'table_name' => 'hub_import_fetch',
      'details' => ['tables' => $tables],
    ]);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clear Import Data</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand">Admin</p>
      <h1>Clear Import Data</h1>
      <?php if (!$confirmed && !empty($messages)): ?>
        <?php foreach ($messages as $message): ?>
          <div class="alert error"><?php echo hub_h($message); ?></div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!$confirmed): ?>
        <div class="alert warning">
          This will clear current import run data and live import rows. It will not delete customers, users, import source settings, or customer mappings.
        </div>
        <div class="links">
          <form method="post" action="/tools/clear_import_data.php" style="margin:0;">
            <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
            <input type="hidden" name="confirm" value="DELETE">
            <button class="but2" type="submit">Confirm clear import data</button>
          </form>
          <a href="/admin/import-sources.php">Cancel</a>
        </div>
      <?php else: ?>
        <div class="alert success">Import data clear requested.</div>
        <?php foreach ($messages as $message): ?>
          <div class="alert info"><?php echo hub_h($message); ?></div>
        <?php endforeach; ?>
        <div class="links">
          <a href="/admin/import-sources.php">Back to imports</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
