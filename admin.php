<?php
require_once __DIR__ . '/includes/app/auth.php';
hub_require_admin();

$messages = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Admin</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Admin</p>
            <h1>Admin dashboard</h1>
            <p class="muted">Manage uploads, processing, and admin tasks.</p>
          </div>
          <div class="links">
            <a href="/dashboard.php">Back to main</a>
            <a href="/admin/customers.php">Customers</a>
            <a href="/admin/users.php">Users</a>
            <a href="/tools/import_sales_orders.php">Run import</a>
            <a href="/tools/sync_customers.php">Sync customers</a>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>

        <div class="dashboard">
          <div class="stat">
            <strong>Data imports</strong>
            <p class="muted">Upload/trigger sales order imports and view results.</p>
          </div>
          <div class="stat">
            <strong>Customer sync</strong>
            <p class="muted">Sync distinct customer codes from sales data.</p>
            <div class="links">
              <a href="/admin/customers.php">Manage customers</a>
            </div>
          </div>
          <div class="stat">
            <strong>Next steps</strong>
            <p class="muted">Future admin tools will appear here.</p>
            <div class="links">
              <a href="/admin/users.php">Manage users</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
