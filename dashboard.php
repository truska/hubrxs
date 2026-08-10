<?php
require_once __DIR__ . '/includes/app/announcements.php';
require_once __DIR__ . '/includes/app/banners.php';
require_once __DIR__ . '/includes/app/dashboard_buttons.php';
require_once __DIR__ . '/includes/app/images.php';
hub_require_login();

$user = hub_current_user();
$messages = hub_flash_messages();
$portalDisplayName = trim((string) ($user['display_name'] ?? ''));
if ($portalDisplayName === '') {
  $portalDisplayName = trim((string) ($user['email'] ?? ''));
  if (strpos($portalDisplayName, '@') !== false) {
    $portalDisplayName = strstr($portalDisplayName, '@', true) ?: $portalDisplayName;
  }
}

global $pdo, $DB_OK;

$portalAccountImage = '';
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_user')) {
  $stmtAccountImage = $pdo->prepare('SELECT image FROM hub_user WHERE id = :id AND archived = 0 LIMIT 1');
  $stmtAccountImage->execute([':id' => (int) ($user['id'] ?? 0)]);
  $accountImageValue = trim((string) ($stmtAccountImage->fetchColumn() ?: ''));
  if ($accountImageValue !== '') {
    $portalAccountImage = hub_image_public_path('content', 'xs', $accountImageValue);
  }
}

if (
  ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
  && isset($_POST['action'])
  && in_array($_POST['action'], ['dismiss_announcement', 'reveal_announcement'], true)
) {
  if (hub_verify_csrf($_POST['csrf'] ?? '')) {
    $announcementId = (int) ($_POST['announcement_id'] ?? 0);
    if ($announcementId > 0) {
      if ($_POST['action'] === 'dismiss_announcement') {
        hub_dismiss_announcement($announcementId, (int) ($user['id'] ?? 0));
      } else {
        hub_reveal_announcement($announcementId, (int) ($user['id'] ?? 0));
      }
    }
  }
  hub_redirect('dashboard.php');
}

$effectiveCustomerId = hub_effective_customer_id($user);
$customerCode = null;
$customerName = null;
$companyLabel = null;
if ($effectiveCustomerId && hub_table_exists('hub_customer') && $DB_OK && ($pdo instanceof PDO)) {
  $stmtCust = $pdo->prepare('SELECT code, name FROM hub_customer WHERE id = :id LIMIT 1');
  $stmtCust->execute([':id' => (int) $effectiveCustomerId]);
  if ($row = $stmtCust->fetch(PDO::FETCH_ASSOC)) {
    $customerCode = trim((string) ($row['code'] ?? ''));
    $customerName = (string) ($row['name'] ?? '');
    $companyLabel = $customerName !== '' ? $customerName : $customerCode;
  }
}

function hub_portal_shipping_count_for_customer_id(?int $customerId, ?array $user = null): int {
  global $pdo, $DB_OK;

  if (
    !$customerId
    || !$DB_OK
    || !($pdo instanceof PDO)
    || !hub_table_exists('hub_so_live')
    || !hub_table_column_exists('hub_so_live', 'customer_id')
  ) {
    return 0;
  }

  $params = [':customer_id' => $customerId];
  $sourceAccessSql = hub_table_column_exists('hub_so_live', 'source_id')
    ? hub_user_source_access_sql('source_id', $user, $params, 'shipping_source')
    : '';
  $projectAccessSql = hub_table_column_exists('hub_so_live', 'project_id')
    ? hub_user_project_code_access_sql('project_id', $user, $params, 'shipping_project')
    : '';

  $stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM hub_so_live
     WHERE customer_id = :customer_id
       AND archived = 0
       AND show_on_web = 1
       AND published = 1' . $sourceAccessSql . $projectAccessSql
  );
  $stmt->execute($params);

  return (int) $stmt->fetchColumn();
}

function hub_portal_inventory_count_for_customer_id(?int $customerId, ?array $user = null): int {
  global $pdo, $DB_OK;

  if (
    !$customerId
    || !$DB_OK
    || !($pdo instanceof PDO)
    || !hub_table_exists('hub_inventory_live')
    || !hub_table_column_exists('hub_inventory_live', 'customer_id')
  ) {
    return 0;
  }

  $params = [':customer_id' => $customerId];
  $sourceAccessSql = hub_table_column_exists('hub_inventory_live', 'source_id')
    ? hub_user_source_access_sql('source_id', $user, $params, 'inventory_source')
    : '';

  $stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM hub_inventory_live
     WHERE customer_id = :customer_id
       AND archived = 0
       AND show_on_web = 1
       AND published = 1' . $sourceAccessSql
  );
  $stmt->execute($params);

  return (int) $stmt->fetchColumn();
}

function hub_key_info_user_image_column(): ?string {
  foreach (['image', 'image_url', 'profile_image', 'avatar_url'] as $column) {
    if (hub_table_column_exists('hub_user', $column)) {
      return $column;
    }
  }
  return null;
}

function hub_key_info_initials(string $name): string {
  $name = trim($name);
  if ($name === '') {
    return 'RX';
  }
  $parts = preg_split('/\s+/', $name) ?: [];
  $first = strtoupper(substr((string) ($parts[0] ?? ''), 0, 1));
  $last = strtoupper(substr((string) ($parts[count($parts) - 1] ?? ''), 0, 1));
  return $first . ($last !== $first ? $last : '');
}

function hub_key_info_image_src(?string $imageValue): string {
  $imageValue = trim((string) $imageValue);
  if ($imageValue === '') {
    return '/filestore/images/admin/md/default-user.svg';
  }
  if (preg_match('#^https?://#i', $imageValue) || str_starts_with($imageValue, '/')) {
    return $imageValue;
  }
  return '/filestore/images/content/sm/' . ltrim($imageValue, '/');
}

function hub_key_info_phone_href(string $phone): string {
  $phone = trim($phone);
  $tel = preg_replace('/[^0-9+]/', '', $phone) ?: '';
  return $tel !== '' ? 'tel:' . $tel : '#';
}

function hub_key_info_linkedin_href(string $linkedin): string {
  $linkedin = trim($linkedin);
  if ($linkedin === '') {
    return '';
  }
  if (preg_match('#^https?://#i', $linkedin)) {
    return $linkedin;
  }
  return 'https://' . ltrim($linkedin, '/');
}

function hub_key_info_contacts_for_customer(?int $customerId): array {
  global $pdo, $DB_OK;

  if (
    !$customerId
    || !$DB_OK
    || !($pdo instanceof PDO)
    || !hub_table_exists('hub_key_info_role')
    || !hub_table_exists('hub_customer_key_info_user')
    || !hub_table_exists('hub_user')
  ) {
    return [];
  }

  $imageColumn = hub_key_info_user_image_column();
  $imageSelect = $imageColumn ? ', u.`' . str_replace('`', '', $imageColumn) . '` AS image_value' : ', NULL AS image_value';
  $stmt = $pdo->prepare(
    'SELECT
       r.role_key, r.label AS role_label, r.section_label, r.default_sort,
       a.sort AS assignment_sort,
       u.display_name, u.email, u.job_title, u.phone, u.linkedin, u.role AS user_role' . $imageSelect . '
     FROM hub_customer_key_info_user a
     INNER JOIN hub_key_info_role r ON r.id = a.role_id
     INNER JOIN hub_user u ON u.id = a.user_id
     WHERE a.customer_id = :customer_id
       AND a.show_on_web = 1
       AND a.archived = 0
       AND r.show_on_web = 1
       AND r.archived = 0
       AND u.archived = 0
     ORDER BY a.sort ASC, r.default_sort ASC, a.id ASC'
  );
  $stmt->execute([':customer_id' => $customerId]);

  $contacts = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    if (hub_role_rank((string) ($row['user_role'] ?? 'user')) < hub_role_rank('admin')) {
      continue;
    }
    $name = trim((string) ($row['display_name'] ?? ''));
    if ($name === '') {
      $name = trim((string) ($row['email'] ?? ''));
    }
    $contacts[] = [
      'section_label' => (string) ($row['section_label'] ?? ''),
      'role_label' => (string) ($row['role_label'] ?? ''),
      'name' => $name,
      'email' => trim((string) ($row['email'] ?? '')),
      'job_title' => trim((string) ($row['job_title'] ?? '')),
      'phone' => trim((string) ($row['phone'] ?? '')),
      'linkedin' => trim((string) ($row['linkedin'] ?? '')),
      'image' => hub_key_info_image_src($row['image_value'] ?? ''),
      'initials' => hub_key_info_initials($name),
    ];
  }

  return $contacts;
}

function hub_key_info_group_contacts(array $contacts): array {
  $groups = [];
  foreach ($contacts as $contact) {
    $section = trim((string) ($contact['section_label'] ?? ''));
    if ($section === '') {
      $section = 'Key Contact';
    }
    $groups[$section][] = $contact;
  }
  return $groups;
}

$activeAnnouncements = hub_active_announcements_for_user((int) ($user['id'] ?? 0));
$dismissedAnnouncements = hub_dismissed_announcements_for_user((int) ($user['id'] ?? 0));
$dashboardBanner = hub_dashboard_banner_active();
$dashboardBannerImage = hub_dashboard_banner_image_src((string) ($dashboardBanner['image'] ?? ''), 'lg');
if ($dashboardBannerImage === '') {
  $dashboardBannerImage = '/filestore/images/content/lg/hubrxsbanner-3000-500.webp';
}
$dashboardBannerAlt = trim((string) ($dashboardBanner['alt_text'] ?? ''));
if ($dashboardBannerAlt === '') {
  $dashboardBannerAlt = 'RxSource Hub';
}
$portalCustomerLabel = $companyLabel ?: 'No customer selected';
$portalShippingCount = hub_portal_shipping_count_for_customer_id($effectiveCustomerId ? (int) $effectiveCustomerId : null, $user);
$portalInventoryCount = hub_portal_inventory_count_for_customer_id($effectiveCustomerId ? (int) $effectiveCustomerId : null, $user);
$portalDashboardAudience = hub_dashboard_audience($user, $effectiveCustomerId ? (int) $effectiveCustomerId : null);
$portalDashboardCounts = [
  'shipping' => $portalShippingCount,
  'inventory' => $portalInventoryCount,
];
$portalDashboardButtons = hub_dashboard_buttons_for_audience($portalDashboardAudience);
$keyInfoContacts = hub_key_info_contacts_for_customer($effectiveCustomerId ? (int) $effectiveCustomerId : null);
$keyInfoColumns = [];
if (!empty($keyInfoContacts)) {
  $keyInfoSplit = (int) ceil(count($keyInfoContacts) / 2);
  $keyInfoColumns = [
    array_slice($keyInfoContacts, 0, $keyInfoSplit),
    array_slice($keyInfoContacts, $keyInfoSplit),
  ];
}
$adminActionCount = 0;
if (hub_is_admin() && $DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_admin_action')) {
  $stmtActions = $pdo->prepare(
    'SELECT COUNT(*)
     FROM hub_admin_action
     WHERE status = :status
       AND (assigned_user_id IS NULL OR assigned_user_id = :user_id)'
  );
  $stmtActions->execute([
    ':status' => 'open',
    ':user_id' => (int) ($user['id'] ?? 0),
  ]);
  $adminActionCount = (int) $stmtActions->fetchColumn();
}

$portalSectionIndex = 0;
$portalSectionAttrs = function (string $name) use (&$portalSectionIndex): string {
  $portalSectionIndex++;
  return ' data-section-index="' . $portalSectionIndex . '" data-section-name="' . hub_h($name) . '"';
};
$portalIsDashboard = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'dashboard.php';
$portalHeaderAction = $portalIsDashboard
  ? [
    'label' => 'Visit our website',
    'href' => 'https://www.rxsource.com/',
    'target' => '_blank',
    'rel' => 'noopener',
  ]
  : [
    'label' => 'Back to Dashboard',
    'href' => '/dashboard.php',
    'target' => '',
    'rel' => '',
  ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Dashboard</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="portal-page portal-dashboard-page">
  <header class="portal-header portal-section"<?php echo $portalSectionAttrs('header'); ?>>
    <a class="portal-logo" href="/dashboard.php" aria-label="RxSource Hub home">
      <img class="portal-logo-image" src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="RxSource Hub">
    </a>
    <div class="portal-welcome portal-welcome-dashboard">
      <span>Welcome <?php echo hub_h($portalDisplayName ?: 'back'); ?></span>
      <?php if ($companyLabel): ?>
        <small><?php echo hub_h($companyLabel); ?></small>
      <?php endif; ?>
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
      <a class="portal-admin-link" href="/faq.php?page=dashboard" title="Help"><i class="<?php echo hub_h(hub_nav_icon_class('help')); ?>" aria-hidden="true"></i><span>Help</span></a>
      <a class="portal-admin-link" href="/logout.php" title="Logout"><i class="<?php echo hub_h(hub_nav_icon_class('logout')); ?>" aria-hidden="true"></i><span>Logout</span></a>
      <a class="portal-website-link" href="<?php echo hub_h($portalHeaderAction['href']); ?>" title="<?php echo hub_h($portalHeaderAction['label']); ?>"<?php echo $portalHeaderAction['target'] !== '' ? ' target="' . hub_h($portalHeaderAction['target']) . '"' : ''; ?><?php echo $portalHeaderAction['rel'] !== '' ? ' rel="' . hub_h($portalHeaderAction['rel']) . '"' : ''; ?>><?php echo hub_h($portalHeaderAction['label']); ?></a>
    </div>
  </header>

  <section class="portal-hero portal-section" aria-label="RxSource Hub banner"<?php echo $portalSectionAttrs('hero'); ?>>
    <img src="<?php echo hub_h($dashboardBannerImage); ?>" alt="<?php echo hub_h($dashboardBannerAlt); ?>" title="<?php echo hub_h($dashboardBannerAlt); ?>">
  </section>

  <?php if (!empty($activeAnnouncements) || !empty($dismissedAnnouncements)): ?>
    <section class="portal-announcements portal-section" aria-label="Key announcements"<?php echo $portalSectionAttrs('announcements'); ?>>
      <?php foreach ($activeAnnouncements as $announcement): ?>
        <?php
          $announcementImageUrl = hub_announcement_image_src((string) ($announcement['image_url'] ?? ''), 'sm');
          $announcementHeading = trim((string) ($announcement['heading'] ?? ''));
          $announcementSubheading = trim((string) ($announcement['subheading'] ?? ''));
          $announcementImageAlt = trim($announcementHeading . ($announcementSubheading !== '' ? ' - ' . $announcementSubheading : ''));
          $announcementImageTitle = $announcementHeading;
          $announcementLinkUrl = trim((string) ($announcement['link_url'] ?? ''));
          $announcementLinkLabel = trim((string) ($announcement['link_label'] ?? ''));
        ?>
        <article class="portal-announcement <?php echo $announcementImageUrl === '' ? 'portal-announcement-no-image' : ''; ?>">
          <?php if ($announcementImageUrl !== ''): ?>
            <img class="portal-announcement-image" src="<?php echo hub_h($announcementImageUrl); ?>" alt="<?php echo hub_h($announcementImageAlt); ?>" title="<?php echo hub_h($announcementImageTitle); ?>">
          <?php endif; ?>
          <div class="portal-announcement-copy">
            <h2><?php echo hub_h((string) $announcement['heading']); ?></h2>
            <?php if (!empty($announcement['subheading'])): ?>
              <p class="portal-announcement-subheading"><?php echo hub_h((string) $announcement['subheading']); ?></p>
            <?php endif; ?>
            <?php if (!empty($announcement['body_html'])): ?>
              <div class="portal-announcement-body"><?php echo hub_announcement_render_html((string) $announcement['body_html']); ?></div>
            <?php endif; ?>
            <div class="portal-announcement-actions">
              <?php if ($announcementLinkUrl !== '' && $announcementLinkLabel !== ''): ?>
                <a class="portal-announcement-link" href="<?php echo hub_h($announcementLinkUrl); ?>">
                  <?php echo hub_h($announcementLinkLabel); ?>
                </a>
              <?php endif; ?>
              <form method="post" action="dashboard.php">
                <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                <input type="hidden" name="action" value="dismiss_announcement">
                <input type="hidden" name="announcement_id" value="<?php echo (int) $announcement['id']; ?>">
                <button type="submit" class="portal-announcement-dismiss">Dismiss</button>
              </form>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
      <?php foreach ($dismissedAnnouncements as $announcement): ?>
        <?php
          $announcementLinkUrl = trim((string) ($announcement['link_url'] ?? ''));
          $announcementLinkLabel = trim((string) ($announcement['link_label'] ?? ''));
        ?>
        <article class="portal-announcement-bar">
          <h2><?php echo hub_h((string) $announcement['heading']); ?></h2>
          <div class="portal-announcement-bar-actions">
            <?php if ($announcementLinkUrl !== '' && $announcementLinkLabel !== ''): ?>
              <a class="portal-announcement-more" href="<?php echo hub_h($announcementLinkUrl); ?>">
                <?php echo hub_h($announcementLinkLabel); ?>
              </a>
            <?php endif; ?>
            <form method="post" action="dashboard.php">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <input type="hidden" name="action" value="reveal_announcement">
              <input type="hidden" name="announcement_id" value="<?php echo (int) $announcement['id']; ?>">
              <button type="submit" class="portal-announcement-reveal">Reveal</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <?php if ($adminActionCount > 0): ?>
    <section class="portal-user-actions portal-actions-complete portal-section" aria-label="Action reminders"<?php echo $portalSectionAttrs('admin-actions'); ?>>
      <div class="portal-user-action-bar">
        <h2>You have Actions to Complete</h2>
        <a class="portal-user-action-link" href="/admin.php#admin-actions">View Actions</a>
      </div>
    </section>
  <?php endif; ?>

  <?php /* include __DIR__ . '/includes/app/dashboard-proposals-glance-legacy.php'; */ ?>
  <?php include __DIR__ . '/includes/app/dashboard-intro-content.php'; ?>

  <section class="portal-dashboard-nav portal-section" aria-label="Dashboard shortcuts"<?php echo $portalSectionAttrs('dashboard-shortcuts'); ?>>
    <div class="portal-dashboard-nav-inner">
      <h2>Your Dashboard</h2>
      <div class="portal-dashboard-tiles">
        <?php foreach ($portalDashboardButtons as $dashboardButton): ?>
          <?php
            $buttonTitle = trim((string) ($dashboardButton['title'] ?? ''));
            $buttonHref = trim((string) ($dashboardButton['href'] ?? 'dashboard.php'));
            $buttonClass = trim((string) ($dashboardButton['css_class'] ?? ''));
            $buttonImage = trim((string) ($dashboardButton['image_url'] ?? ''));
            $buttonCount = hub_dashboard_button_count($dashboardButton, $portalDashboardCounts);
            $buttonStyle = $buttonImage !== ''
              ? ' style="background-image: linear-gradient(rgba(38, 51, 59, 0.10), rgba(38, 51, 59, 0.40)), url(\'' . hub_h(hub_public_asset_url($buttonImage)) . '\');"'
              : '';
          ?>
          <a class="portal-dashboard-tile <?php echo hub_h($buttonClass); ?>" href="<?php echo hub_h($buttonHref !== '' ? $buttonHref : 'dashboard.php'); ?>" title="<?php echo hub_h($buttonTitle); ?>"<?php echo $buttonStyle; ?>>
            <?php if ($buttonCount !== null): ?>
              <strong class="portal-dashboard-tile-count"><?php echo number_format($buttonCount); ?></strong>
            <?php endif; ?>
            <span><?php echo hub_h($buttonTitle); ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="portal-team portal-key-information portal-section" aria-label="Your Rx contacts"<?php echo $portalSectionAttrs('contacts'); ?>>
    <div class="portal-team-inner">
      <h2 class="portal-team-heading">Key Information</h2>
      <?php if (empty($keyInfoContacts)): ?>
        <p class="portal-team-empty">Key Information still to be added</p>
      <?php else: ?>
        <?php foreach ($keyInfoColumns as $columnContacts): ?>
          <?php if (empty($columnContacts)) continue; ?>
          <div class="portal-team-column">
            <?php foreach ($columnContacts as $contact): ?>
              <article class="portal-team-member">
                <div class="portal-team-avatar">
                  <img src="<?php echo hub_h((string) $contact['image']); ?>" alt="">
                </div>
                <div class="portal-team-copy">
                  <?php if (!empty($contact['role_label'])): ?>
                    <p class="portal-team-role"><?php echo hub_h((string) $contact['role_label']); ?></p>
                  <?php endif; ?>
                  <h3>
                    <?php echo hub_h((string) $contact['name']); ?>
                    <?php if (!empty($contact['job_title'])): ?>
                      <em><?php echo hub_h((string) $contact['job_title']); ?></em>
                    <?php endif; ?>
                  </h3>
                  <?php if (!empty($contact['email'])): ?>
                    <p><span class="portal-team-icon" aria-hidden="true"><i class="fa-regular fa-envelope"></i></span><a class="portal-team-contact-link" href="mailto:<?php echo hub_h((string) $contact['email']); ?>"><?php echo hub_h((string) $contact['email']); ?></a></p>
                  <?php endif; ?>
                  <?php if (!empty($contact['phone'])): ?>
                    <p><span class="portal-team-icon" aria-hidden="true"><i class="fa-solid fa-phone"></i></span><a class="portal-team-contact-link" href="<?php echo hub_h(hub_key_info_phone_href((string) $contact['phone'])); ?>"><?php echo hub_h((string) $contact['phone']); ?></a></p>
                  <?php endif; ?>
                  <?php $linkedinHref = hub_key_info_linkedin_href((string) ($contact['linkedin'] ?? '')); ?>
                  <?php if ($linkedinHref !== ''): ?>
                    <p><span class="portal-team-icon" aria-hidden="true"><i class="fa-brands fa-linkedin-in"></i></span><a class="portal-team-contact-link" href="<?php echo hub_h($linkedinHref); ?>" target="_blank" rel="noopener">LinkedIn profile</a></p>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="portal-news portal-section" aria-label="RxSource LinkedIn news feed"<?php echo $portalSectionAttrs('linkedin-feed'); ?>>
    <div class="portal-news-inner">
      <h2>RxSource News Feed</h2>
      <div class="portal-news-grid">
        <?php for ($feedItem = 1; $feedItem <= 4; $feedItem++): ?>
          <a class="portal-news-card" href="https://www.linkedin.com/company/rxsource">
            <div class="portal-news-card-header">
              <span class="portal-news-dot"></span>
              <strong>RxSource</strong>
            </div>
            <div class="portal-news-card-body">
              <span>LinkedIn post preview</span>
            </div>
            <div class="portal-news-card-footer">View on LinkedIn</div>
          </a>
        <?php endfor; ?>
      </div>
    </div>
  </section>

  <footer class="portal-footer portal-section"<?php echo $portalSectionAttrs('footer'); ?>>
    <div class="portal-footer-inner">
      <div class="portal-footer-addresses">
        <div>
          <strong>CANADA</strong>
          <p>74-556 Edward Ave<br>Richmond Hill<br>Canada<br>L4C 9Y5</p>
          <p>+1 905 883 4333</p>
        </div>
        <div>
          <strong>USA</strong>
          <p>Unit 300<br>1240 Forest Parkway<br>West Deptford<br>New Jersey<br>USA<br>08066</p>
          <p>+1 905 883 4333</p>
        </div>
        <div>
          <strong>EUROPE</strong>
          <p>Unit 506<br>Northwest Business Park, Ballycoolin<br>Dublin 15<br>Ireland</p>
          <p>+353 (1) 963-1100</p>
        </div>
      </div>
      <div class="portal-footer-contact">
        <div class="portal-socials" aria-label="RxSource social links">
          <a href="https://www.linkedin.com/company/rxsource" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
          <a href="https://twitter.com/rxsource" aria-label="X / Twitter"><i class="fa-brands fa-x-twitter"></i></a>
          <a href="https://www.instagram.com/rxsource" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
          <a href="https://www.youtube.com/@RxSource" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
        </div>
        <a href="mailto:solutions@rxsource.com">solutions@rxsource.com</a>
        <div class="portal-user-tools" aria-label="Account tools">
          <a class="portal-account-tool" href="/account.php" aria-label="Manage account">
            <?php if ($portalAccountImage !== ''): ?>
              <img src="<?php echo hub_h($portalAccountImage); ?>" alt="">
            <?php else: ?>
              <i class="fa-solid fa-user" aria-hidden="true"></i>
            <?php endif; ?>
            <span>Account</span>
          </a>
        </div>
      </div>
    </div>
    <div class="portal-footer-legal">
      <div class="portal-footer-legal-inner">
        <p>&copy; <?php echo date('Y'); ?> RxSource. All rights reserved.</p>
        <nav aria-label="Legal policies">
          <a href="/privacy-policy.php">Privacy Policy</a>
          <a href="/cookie-policy.php">Cookie Policy</a>
          <a href="/terms.php">Terms of Use</a>
          <a href="/accessibility.php">Accessibility</a>
        </nav>
      </div>
    </div>
  </footer>
</body>
</html>
