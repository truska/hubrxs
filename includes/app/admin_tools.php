<?php
require_once __DIR__ . '/auth.php';

function hub_admin_tool_groups(): array {
  return [
    'manager_users' => [
      'title' => 'Users',
      'description' => 'Manage company users and assignments as manager tools are enabled.',
      'sort' => 100,
    ],
    'manager_reports' => [
      'title' => 'Reports',
      'description' => 'Open customer-facing reports for the current company view.',
      'sort' => 200,
    ],
    'customers' => [
      'title' => 'Customers',
      'description' => 'Manage customer records used for filtering and access.',
      'sort' => 100,
    ],
    'projects' => [
      'title' => 'Projects',
      'description' => 'Review imported projects and complete missing details.',
      'sort' => 200,
    ],
    'users' => [
      'title' => 'Users',
      'description' => 'Manage hub users, roles, and customer assignments.',
      'sort' => 300,
    ],
    'imports' => [
      'title' => 'Data Imports',
      'description' => 'Manage JSON feed sources, trigger imports, and review results.',
      'sort' => 400,
    ],
    'content' => [
      'title' => 'Content',
      'description' => 'Manage published content, testimonials, FAQs, and policy pages.',
      'sort' => 500,
    ],
    'tools' => [
      'title' => 'Tools',
      'description' => 'Utility tools, setup helpers, audit records, and maintenance actions.',
      'sort' => 600,
    ],
  ];
}

function hub_admin_tools_registry(): array {
  return [
    [
      'key' => 'manager_manage_users',
      'title' => 'Manage Users',
      'href' => '/admin/users.php?scope=company',
      'group' => 'manager_users',
      'minimum_role' => 'manager',
      'lane' => 'manager',
      'sort' => 100,
    ],
    [
      'key' => 'manager_client_dashboard',
      'title' => 'Open Client',
      'href' => '/dashboard.php',
      'group' => 'manager_reports',
      'minimum_role' => 'manager',
      'lane' => 'manager',
      'sort' => 100,
    ],
    [
      'key' => 'manager_shipping',
      'title' => 'Open Shipping',
      'href' => '/client-portal-shipping.php',
      'group' => 'manager_reports',
      'minimum_role' => 'manager',
      'lane' => 'manager',
      'sort' => 200,
    ],
    [
      'key' => 'manager_inventory',
      'title' => 'Open Inventory',
      'href' => '/client-portal-inventory.php',
      'group' => 'manager_reports',
      'minimum_role' => 'manager',
      'lane' => 'manager',
      'sort' => 300,
    ],
    [
      'key' => 'manage_customers',
      'title' => 'Manage',
      'href' => '/admin/customers.php',
      'group' => 'customers',
      'minimum_role' => 'admin',
      'lane' => 'internal',
      'sort' => 100,
    ],
    [
      'key' => 'sync_customers',
      'title' => 'Sync',
      'href' => '/tools/sync_customers.php',
      'group' => 'customers',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 200,
    ],
    [
      'key' => 'key_people',
      'title' => 'Key People',
      'href' => '/admin/key-people.php',
      'group' => 'customers',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 300,
    ],
    [
      'key' => 'map_customers',
      'title' => 'Map',
      'href' => '/admin/map-customers.php',
      'group' => 'customers',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 400,
    ],
    [
      'key' => 'manage_projects',
      'title' => 'Manage',
      'href' => '/admin/projects.php',
      'group' => 'projects',
      'minimum_role' => 'admin',
      'lane' => 'internal',
      'sort' => 100,
    ],
    [
      'key' => 'map_projects',
      'title' => 'Map',
      'href' => '/admin/map-projects.php',
      'group' => 'projects',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 200,
    ],
    [
      'key' => 'manage_users',
      'title' => 'Manage',
      'href' => '/admin/users.php',
      'group' => 'users',
      'minimum_role' => 'admin',
      'lane' => 'internal',
      'sort' => 100,
    ],
    [
      'key' => 'manage_imports',
      'title' => 'Manage',
      'href' => '/admin/import-sources.php',
      'group' => 'imports',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 100,
    ],
    [
      'key' => 'view_import_data',
      'title' => 'View',
      'href' => '/admin/import-data.php',
      'group' => 'imports',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 200,
    ],
    [
      'key' => 'view_json',
      'title' => 'JSON',
      'href' => '/admin/view-json.php',
      'group' => 'imports',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 300,
    ],
    [
      'key' => 'policy_pages',
      'title' => 'Policies',
      'href' => '/admin/content.php',
      'group' => 'content',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 600,
    ],
    [
      'key' => 'announcements',
      'title' => 'Announcements',
      'href' => '/admin/announcements.php',
      'group' => 'content',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 200,
    ],
    [
      'key' => 'testimonials',
      'title' => 'Testimonials',
      'href' => '/admin/testimonials.php',
      'group' => 'content',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 300,
    ],
    [
      'key' => 'dashboard_banner',
      'title' => 'Banner',
      'href' => '/admin/banners.php',
      'group' => 'content',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 400,
    ],
    [
      'key' => 'manage_faqs',
      'title' => 'FAQs',
      'href' => '/admin/faqs.php',
      'group' => 'content',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 500,
    ],
    [
      'key' => 'dashboard_buttons',
      'title' => 'Dashboard',
      'href' => '/admin/dashboard-buttons.php',
      'group' => 'tools',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 100,
    ],
    [
      'key' => 'help_messages',
      'title' => 'Help',
      'href' => '/admin/help-messages.php',
      'group' => 'tools',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 200,
    ],
    [
      'key' => 'report_columns',
      'title' => 'Report Columns',
      'href' => '/admin/report-columns.php',
      'group' => 'tools',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 250,
    ],
    [
      'key' => 'legacy_import',
      'title' => 'Legacy Import',
      'href' => '/tools/import_sales_orders.php',
      'group' => 'tools',
      'minimum_role' => 'developer',
      'lane' => 'internal',
      'sort' => 300,
    ],
    [
      'key' => 'audit_log',
      'title' => 'Audit Log',
      'href' => '/admin/audit-log.php',
      'group' => 'tools',
      'minimum_role' => 'super_admin',
      'lane' => 'internal',
      'sort' => 400,
    ],
    [
      'key' => 'backfill_project_customers',
      'title' => 'Backfill Project Customers',
      'href' => '/tools/backfill_project_customers.php',
      'group' => 'tools',
      'minimum_role' => 'developer',
      'lane' => 'internal',
      'sort' => 500,
    ],
    [
      'key' => 'schema_tool',
      'title' => 'Schema Tools',
      'href' => '/tools/install_hub_schema.php',
      'group' => 'tools',
      'minimum_role' => 'developer',
      'lane' => 'internal',
      'sort' => 600,
    ],
  ];
}

function hub_admin_panel_role_limit(string $panel): string {
  if ($panel === 'developer') {
    return 'developer';
  }
  if ($panel === 'super') {
    return 'super_admin';
  }
  if ($panel === 'admin') {
    return 'admin';
  }
  return 'manager';
}

function hub_admin_tools_for_panel(string $panel, ?array $user = null): array {
  if ($user === null) {
    $user = hub_current_user();
  }

  $lane = $panel === 'manager' ? 'manager' : 'internal';
  $panelRank = hub_role_rank(hub_admin_panel_role_limit($panel));
  $userRank = hub_role_rank(hub_role_key($user));
  $rankLimit = min($panelRank, $userRank);

  $tools = [];
  foreach (hub_admin_tools_registry() as $tool) {
    if (($tool['lane'] ?? 'internal') !== $lane) {
      continue;
    }
    $minimumRole = (string) ($tool['minimum_role'] ?? 'user');
    if (hub_role_rank($minimumRole) > $rankLimit) {
      continue;
    }
    $tools[] = $tool;
  }

  usort($tools, static function (array $left, array $right): int {
    $groupCompare = strcmp((string) ($left['group'] ?? ''), (string) ($right['group'] ?? ''));
    if ($groupCompare !== 0) {
      return $groupCompare;
    }
    return ((int) ($left['sort'] ?? 100)) <=> ((int) ($right['sort'] ?? 100));
  });

  return $tools;
}

function hub_admin_tool_cards_for_panel(string $panel, ?array $user = null): array {
  $groupDefinitions = hub_admin_tool_groups();
  $cards = [];
  $standardGroups = $panel === 'manager'
    ? ['manager_users', 'manager_reports']
    : ['customers', 'projects', 'users', 'imports', 'content', 'tools'];

  foreach ($standardGroups as $groupKey) {
    $group = $groupDefinitions[$groupKey] ?? [
      'title' => ucwords(str_replace('_', ' ', $groupKey)),
      'description' => '',
      'sort' => 999,
    ];
    $cards[$groupKey] = [
      'key' => $groupKey,
      'title' => (string) ($group['title'] ?? $groupKey),
      'description' => (string) ($group['description'] ?? ''),
      'sort' => (int) ($group['sort'] ?? 999),
      'tools' => [],
    ];
  }

  foreach (hub_admin_tools_for_panel($panel, $user) as $tool) {
    $groupKey = (string) ($tool['group'] ?? 'tools');
    if (!isset($cards[$groupKey])) {
      $group = $groupDefinitions[$groupKey] ?? [
        'title' => ucwords(str_replace('_', ' ', $groupKey)),
        'description' => '',
        'sort' => 999,
      ];
      $cards[$groupKey] = [
        'key' => $groupKey,
        'title' => (string) ($group['title'] ?? $groupKey),
        'description' => (string) ($group['description'] ?? ''),
        'sort' => (int) ($group['sort'] ?? 999),
        'tools' => [],
      ];
    }
    $cards[$groupKey]['tools'][] = $tool;
  }

  uasort($cards, static function (array $left, array $right): int {
    $sort = ((int) ($left['sort'] ?? 999)) <=> ((int) ($right['sort'] ?? 999));
    if ($sort !== 0) {
      return $sort;
    }
    return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
  });

  foreach ($cards as &$card) {
    usort($card['tools'], static function (array $left, array $right): int {
      $sort = ((int) ($left['sort'] ?? 100)) <=> ((int) ($right['sort'] ?? 100));
      if ($sort !== 0) {
        return $sort;
      }
      return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    });
  }
  unset($card);

  return array_values($cards);
}

function hub_admin_render_tool_cards(string $panel, ?array $user = null, array $options = []): string {
  $cards = hub_admin_tool_cards_for_panel($panel, $user);
  $emptyMessage = (string) ($options['empty_message'] ?? 'No tools are available for your current role.');
  $wrap = !array_key_exists('wrap', $options) || (bool) $options['wrap'];

  ob_start();
  ?>
  <?php if ($wrap): ?>
    <div class="dashboard">
  <?php endif; ?>
    <?php if (empty($cards)): ?>
      <div class="stat">
        <strong>Tools</strong>
        <p class="muted"><?php echo hub_h($emptyMessage); ?></p>
      </div>
    <?php else: ?>
      <?php foreach ($cards as $card): ?>
        <div class="stat">
          <strong><?php echo hub_h((string) ($card['title'] ?? 'Tools')); ?></strong>
          <?php if (trim((string) ($card['description'] ?? '')) !== ''): ?>
            <p class="muted"><?php echo hub_h((string) $card['description']); ?></p>
          <?php endif; ?>
          <div class="links">
            <?php if (empty($card['tools'])): ?>
              <span class="muted">No tools at this level.</span>
            <?php else: ?>
              <?php foreach (($card['tools'] ?? []) as $tool): ?>
                <?php $roleClass = preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string) ($tool['minimum_role'] ?? 'user'))) ?: 'user'; ?>
                <a class="admin-tool-link admin-tool-role-<?php echo hub_h($roleClass); ?>" href="<?php echo hub_h((string) ($tool['href'] ?? '#')); ?>"><?php echo hub_h((string) ($tool['title'] ?? 'Open')); ?></a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php if ($wrap): ?>
    </div>
  <?php endif; ?>
  <?php
  return (string) ob_get_clean();
}
