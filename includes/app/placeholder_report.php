<?php
require_once __DIR__ . '/auth.php';

function hub_render_placeholder_report(string $title, string $pageKey): void {
  hub_require_login();

  $user = hub_current_user();
  $portalDisplayName = trim((string) ($user['display_name'] ?? ''));
  if ($portalDisplayName === '') {
    $portalDisplayName = trim((string) ($user['email'] ?? ''));
    if (strpos($portalDisplayName, '@') !== false) {
      $portalDisplayName = strstr($portalDisplayName, '@', true) ?: $portalDisplayName;
    }
  }

  global $pdo, $DB_OK;
  $customerLabel = 'No customer selected';
  $effectiveCustomerId = hub_effective_customer_id($user);
  if ($effectiveCustomerId && $DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
    $stmt = $pdo->prepare('SELECT code, name FROM hub_customer WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $effectiveCustomerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($customer) {
      $code = trim((string) ($customer['code'] ?? ''));
      $name = trim((string) ($customer['name'] ?? ''));
      $customerLabel = $name !== '' ? $name : ($code !== '' ? $code : 'No customer selected');
    }
  }
  ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | <?php echo hub_h($title); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="portal-page portal-report-page">
  <header class="portal-header">
    <a class="portal-logo" href="/dashboard.php" aria-label="RxSource Hub home">
      <img class="portal-logo-image" src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="RxSource Hub">
    </a>
    <div class="portal-welcome">
      <?php echo hub_h($portalDisplayName ?: 'User'); ?><?php echo $customerLabel !== 'No customer selected' ? ' [' . hub_h($customerLabel) . ']' : ''; ?>
    </div>
    <div class="portal-header-actions">
      <?php if (hub_role_at_least('manager')): ?>
        <a class="portal-admin-link" href="/manager.php" title="Manager"><i class="<?php echo hub_h(hub_nav_icon_class('manager')); ?>" aria-hidden="true"></i><span>Manager</span></a>
      <?php endif; ?>
      <?php if (hub_role_at_least('admin')): ?>
        <a class="portal-admin-link" href="/admin.php" title="Admin"><i class="<?php echo hub_h(hub_nav_icon_class('admin')); ?>" aria-hidden="true"></i><span>Admin</span></a>
      <?php endif; ?>
      <?php if (hub_role_at_least('super_admin')): ?>
        <a class="portal-admin-link" href="/super.php" title="Super"><i class="<?php echo hub_h(hub_nav_icon_class('super')); ?>" aria-hidden="true"></i><span>Super</span></a>
      <?php endif; ?>
      <?php if (hub_is_developer()): ?>
        <a class="portal-admin-link" href="/developer.php" title="Developer"><i class="<?php echo hub_h(hub_nav_icon_class('dev')); ?>" aria-hidden="true"></i><span>Dev</span></a>
      <?php endif; ?>
      <a class="portal-admin-link" href="/faq.php?page=<?php echo hub_h($pageKey); ?>" title="Help"><i class="<?php echo hub_h(hub_nav_icon_class('help')); ?>" aria-hidden="true"></i><span>Help</span></a>
      <a class="portal-admin-link" href="/logout.php" title="Logout"><i class="<?php echo hub_h(hub_nav_icon_class('logout')); ?>" aria-hidden="true"></i><span>Logout</span></a>
      <a class="portal-website-link" href="/dashboard.php" title="Back to Dashboard">Back to Dashboard</a>
    </div>
  </header>

  <main class="stack portal-section portal-content">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand"><?php echo hub_h($title); ?></p>
          <h1><?php echo hub_h($title); ?></h1>
        </div>
        <div class="links">
          <a href="/dashboard.php">Back to summary</a>
        </div>
      </div>
    </div>

    <div class="card" id="<?php echo hub_h($pageKey); ?>-report">
      <div class="flex">
        <div>
          <p class="brand">Reports</p>
          <h1><?php echo hub_h($title); ?></h1>
        </div>
      </div>
      <div class="alert info">Sorry, there is no data available yet.</div>
      <div class="report-record-total">Total records selected: 0</div>
    </div>
  </main>
</body>
</html>
  <?php
}
