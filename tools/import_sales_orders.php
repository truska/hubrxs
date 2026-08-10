<?php
require_once __DIR__ . '/../includes/app/auth.php';

$isCli = (php_sapi_name() === 'cli');
$msg = 'Legacy uploaded sales order import has been retired. Use the remote SO Portal Lines import instead.';

if ($isCli) {
  fwrite(STDERR, $msg . PHP_EOL);
  exit(1);
}

hub_require_admin();
http_response_code(410);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales Order Import Retired</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="wrap">
    <div class="card">
      <p class="brand">Admin</p>
      <h1>Sales Order Import Retired</h1>
      <div class="alert info"><?php echo hub_h($msg); ?></div>
      <div class="links">
        <a href="/admin/import-sources.php">Import sources</a>
        <a href="/dashboard.php">Dashboard</a>
      </div>
    </div>
  </div>
</body>
</html>