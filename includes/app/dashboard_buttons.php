<?php
require_once __DIR__ . '/auth.php';

function hub_customer_type_options(): array {
  return [
    'customer' => 'Customer',
    'prospect' => 'Prospect',
  ];
}

function hub_valid_customer_type(string $value): string {
  $value = strtolower(trim($value));
  return array_key_exists($value, hub_customer_type_options()) ? $value : 'customer';
}

function hub_dashboard_audience(?array $user = null, ?int $customerId = null): string {
  global $pdo, $DB_OK;

  if ($user === null) {
    $user = hub_current_user();
  }
  $overrideCustomerId = hub_current_customer_override();
  if (hub_role_at_least('admin', $user)) {
    if ($overrideCustomerId === null) {
      return 'staff';
    }
    $customerId = $overrideCustomerId;
  }
  if (!$customerId) {
    $customerId = hub_effective_customer_id($user);
  }
  if (
    $customerId
    && $DB_OK
    && ($pdo instanceof PDO)
    && hub_table_exists('hub_customer')
    && hub_table_column_exists('hub_customer', 'customer_type')
  ) {
    $stmt = $pdo->prepare('SELECT customer_type FROM hub_customer WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $customerId]);
    return hub_valid_customer_type((string) ($stmt->fetchColumn() ?: 'customer'));
  }
  return 'customer';
}

function hub_dashboard_default_buttons(): array {
  return [
    ['button_key' => 'shipping', 'title' => 'Shipping Report', 'href' => '/client-portal-shipping.php', 'css_class' => 'portal-dashboard-tile-shipping', 'image_url' => '', 'count_key' => 'shipping', 'show_customer' => 1, 'show_prospect' => 0, 'show_staff' => 0, 'sort' => 10],
    ['button_key' => 'inventory', 'title' => 'Inventory Report', 'href' => '/client-portal-inventory.php', 'css_class' => 'portal-dashboard-tile-inventory', 'image_url' => '', 'count_key' => 'inventory', 'show_customer' => 1, 'show_prospect' => 0, 'show_staff' => 0, 'sort' => 20],
    ['button_key' => 'kpi', 'title' => 'KPIs/Metric', 'href' => '/client-portal-kpis.php', 'css_class' => 'portal-dashboard-tile-kpi', 'image_url' => '', 'count_key' => '', 'show_customer' => 1, 'show_prospect' => 0, 'show_staff' => 1, 'sort' => 30],
    ['button_key' => 'artifacts', 'title' => 'Project Artifacts', 'href' => '/client-portal-project-artifacts.php', 'css_class' => 'portal-dashboard-tile-artifacts', 'image_url' => '', 'count_key' => '', 'show_customer' => 1, 'show_prospect' => 0, 'show_staff' => 1, 'sort' => 40],
    ['button_key' => 'proposals', 'title' => 'Proposals, Change Orders and Contracts', 'href' => '/client-portal-proposals.php', 'css_class' => 'portal-dashboard-tile-proposals', 'image_url' => '', 'count_key' => '', 'show_customer' => 0, 'show_prospect' => 1, 'show_staff' => 0, 'sort' => 50],
    ['button_key' => 'testimonials', 'title' => 'Testimonials', 'href' => '/testimonials.php', 'css_class' => 'portal-dashboard-tile-testimonials', 'image_url' => '', 'count_key' => '', 'show_customer' => 0, 'show_prospect' => 1, 'show_staff' => 1, 'sort' => 60],
    ['button_key' => 'marketing', 'title' => 'Marketing', 'href' => '/client-portal-marketing.php', 'css_class' => 'portal-dashboard-tile-marketing', 'image_url' => '', 'count_key' => '', 'show_customer' => 0, 'show_prospect' => 1, 'show_staff' => 1, 'sort' => 70],
  ];
}

function hub_dashboard_buttons_for_audience(string $audience): array {
  global $pdo, $DB_OK;

  $audience = in_array($audience, ['customer', 'prospect', 'staff'], true) ? $audience : 'customer';
  $visibilityColumn = 'show_' . $audience;

  if (
    $DB_OK
    && ($pdo instanceof PDO)
    && hub_table_exists('hub_dashboard_button')
    && hub_table_column_exists('hub_dashboard_button', $visibilityColumn)
  ) {
    $stmt = $pdo->query(
      'SELECT button_key, title, href, css_class, image_url, count_key, sort
       FROM hub_dashboard_button
       WHERE archived = 0
         AND show_on_web = 1
         AND ' . $visibilityColumn . ' = 1
       ORDER BY sort ASC, title ASC'
    );
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    if (!empty($rows)) {
      return $rows;
    }
  }

  return array_values(array_filter(hub_dashboard_default_buttons(), static function (array $button) use ($visibilityColumn): bool {
    return !empty($button[$visibilityColumn]);
  }));
}

function hub_dashboard_button_count(array $button, array $counts): ?int {
  $countKey = trim((string) ($button['count_key'] ?? ''));
  if ($countKey === '' || !array_key_exists($countKey, $counts)) {
    return null;
  }
  return (int) $counts[$countKey];
}
