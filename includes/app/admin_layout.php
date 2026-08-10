<?php
require_once __DIR__ . '/auth.php';

function hub_admin_display_name(): string {
  $user = hub_current_user();
  $name = trim((string) ($user['display_name'] ?? ''));
  if ($name !== '') {
    return $name;
  }
  $email = trim((string) ($user['email'] ?? ''));
  if ($email !== '' && strpos($email, '@') !== false) {
    return strstr($email, '@', true) ?: $email;
  }
  return $email !== '' ? $email : 'Admin';
}
function hub_admin_effective_customer_label(): string {
  $user = hub_current_user();
  $customerId = hub_effective_customer_id($user);
  if (!$customerId) {
    return '';
  }

  global $pdo, $DB_OK;
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_customer')) {
    return '';
  }

  try {
    $stmt = $pdo->prepare('SELECT name, code FROM hub_customer WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return '';
    }
    $name = trim((string) ($row['name'] ?? ''));
    $code = trim((string) ($row['code'] ?? ''));
    return $name !== '' ? $name : $code;
  } catch (PDOException $e) {
    return '';
  }
}

function hub_admin_header(string $active = ''): string {
  $items = [
    'client' => ['label' => 'Client', 'href' => '/dashboard.php', 'show' => true, 'icon' => hub_nav_icon_class('client')],
    'manager' => ['label' => 'Manager', 'href' => '/manager.php', 'show' => hub_role_at_least('manager'), 'icon' => hub_nav_icon_class('manager')],
    'admin' => ['label' => 'Admin', 'href' => '/admin.php', 'show' => hub_role_at_least('admin'), 'icon' => hub_nav_icon_class('admin')],
    'super' => ['label' => 'Super', 'href' => '/super.php', 'show' => hub_role_at_least('super_admin'), 'icon' => hub_nav_icon_class('super')],
    'dev' => ['label' => 'Dev', 'href' => '/developer.php', 'show' => hub_is_developer(), 'icon' => hub_nav_icon_class('dev')],
    'help' => ['label' => 'Help', 'href' => '/faq.php', 'show' => true, 'icon' => hub_nav_icon_class('help')],
    'logout' => ['label' => 'Logout', 'href' => '/logout.php', 'show' => true, 'icon' => hub_nav_icon_class('logout')],
  ];

  ob_start();
  ?>
  <header class="admin-header">
    <a class="admin-header-logo" href="/dashboard.php" aria-label="RxSource Hub dashboard">
      <img src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="RxSource Hub">
    </a>
    <div class="admin-header-user">
      <?php echo hub_h(hub_admin_display_name()); ?><?php echo hub_admin_effective_customer_label() !== '' ? ' [' . hub_h(hub_admin_effective_customer_label()) . ']' : ''; ?>
    </div>
    <nav class="admin-header-nav" aria-label="Admin navigation">
      <?php foreach ($items as $key => $item): ?>
        <?php if (empty($item['show'])) continue; ?>
        <a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo hub_h($item['href']); ?>" title="<?php echo hub_h($item['label']); ?>">
          <i class="<?php echo hub_h((string) ($item['icon'] ?? '')); ?>" aria-hidden="true"></i><span><?php echo hub_h($item['label']); ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </header>
  <?php
  return (string) ob_get_clean();
}
