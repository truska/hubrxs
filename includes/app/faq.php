<?php
require_once __DIR__ . '/help.php';
require_once __DIR__ . '/content.php';

function hub_faq_role_options(): array {
  $roles = hub_user_role_options(true);
  if (empty($roles)) {
    $roles = [
      ['role_key' => 'user', 'label' => 'User', 'rank' => 10],
      ['role_key' => 'manager', 'label' => 'Manager', 'rank' => 20],
      ['role_key' => 'admin', 'label' => 'Admin', 'rank' => 30],
      ['role_key' => 'super_admin', 'label' => 'Super Admin', 'rank' => 40],
      ['role_key' => 'developer', 'label' => 'Developer', 'rank' => 50],
    ];
  }
  return $roles;
}

function hub_faq_role_label(string $roleKey): string {
  foreach (hub_faq_role_options() as $role) {
    if (($role['role_key'] ?? '') === $roleKey) {
      return (string) ($role['label'] ?? $roleKey);
    }
  }
  return ucwords(str_replace('_', ' ', $roleKey));
}

function hub_faq_allowed_sections(?array $user = null): array {
  $currentRank = hub_role_rank(hub_role_key($user));
  $allowed = [];
  foreach (hub_faq_role_options() as $role) {
    $rank = (int) ($role['rank'] ?? 0);
    $key = (string) ($role['role_key'] ?? '');
    if ($key !== '' && $rank <= $currentRank) {
      $allowed[] = $key;
    }
  }
  if (empty($allowed)) {
    $allowed[] = 'user';
  }
  return array_values(array_unique($allowed));
}

function hub_faq_page_options(): array {
  return [
    '' => 'All / general',
    'dashboard' => 'Dashboard',
    'shipping' => 'Shipping report',
    'inventory' => 'Inventory report',
    'so-lines' => 'SO portal lines',
    'manager' => 'Manager home',
    'admin' => 'Admin home',
    'super' => 'Super admin home',
    'developer' => 'Developer home',
    'imports' => 'Imports',
    'users' => 'Users',
    'customers' => 'Customers',
    'projects' => 'Projects',
  ];
}

function hub_faq_page_label(string $pageKey): string {
  $options = hub_faq_page_options();
  return $options[$pageKey] ?? ucwords(str_replace(['-', '_'], ' ', $pageKey));
}

function hub_faq_normalise_page_key(string $pageKey): string {
  $pageKey = strtolower(trim($pageKey));
  $pageKey = preg_replace('/[^a-z0-9_-]+/', '-', $pageKey) ?: '';
  return trim($pageKey, '-');
}
