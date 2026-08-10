<?php
require_once __DIR__ . '/includes/app/admin_layout.php';
require_once __DIR__ . '/includes/app/admin_tools.php';
hub_require_super_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Super Admin Home</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
  <?php echo hub_admin_header('super'); ?>
  <div class="stack">
    <div class="stack">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Super</p>
            <h1>Super Admin Home</h1>
            <p class="muted">Higher-level administration and general content tools live here.</p>
          </div>
          <div class="admin-customer-view-control">
            <p class="muted">Current view: <?php echo hub_h(hub_admin_effective_customer_label() !== '' ? hub_admin_effective_customer_label() : 'No customer selected'); ?></p>
            <div class="links">
              <a href="/admin.php?change_customer=1">Change Customer</a>
              <form method="post" action="/admin.php" style="margin:0; display:contents;">
                <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                <input type="hidden" name="action" value="reset_customer_override">
                <button type="submit">Reset Customer</button>
              </form>
            </div>
          </div>
        </div>
        <?php echo hub_admin_render_tool_cards('super'); ?>
      </div>
    </div>
  </div>
</body>
</html>
