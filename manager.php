<?php
require_once __DIR__ . '/includes/app/admin_layout.php';
require_once __DIR__ . '/includes/app/admin_tools.php';
hub_require_manager();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Manager Home</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
  <?php echo hub_admin_header('manager'); ?>
  <div class="stack">
    <div class="stack">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Manager</p>
            <h1>Manager Home</h1>
            <p class="muted">Company-scoped tools for the current customer view.</p>
          </div>
        </div>
        <?php echo hub_admin_render_tool_cards('manager'); ?>
      </div>
    </div>
  </div>
</body>
</html>
