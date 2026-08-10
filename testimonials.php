<?php
require_once __DIR__ . '/includes/app/testimonials.php';
hub_require_login();

$user = hub_current_user();
$portalDisplayName = trim((string) ($user['display_name'] ?? ''));
if ($portalDisplayName === '') {
  $portalDisplayName = trim((string) ($user['email'] ?? ''));
  if (strpos($portalDisplayName, '@') !== false) {
    $portalDisplayName = strstr($portalDisplayName, '@', true) ?: $portalDisplayName;
  }
}

$testimonials = hub_testimonials_published();

$portalSectionIndex = 0;
$portalSectionAttrs = function (string $name) use (&$portalSectionIndex): string {
  $portalSectionIndex++;
  return ' data-section-index="' . $portalSectionIndex . '" data-section-name="' . hub_h($name) . '"';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Testimonials</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="portal-page portal-testimonials-page">
  <header class="portal-header portal-section"<?php echo $portalSectionAttrs('header'); ?>>
    <a class="portal-logo" href="/dashboard.php" aria-label="RxSource Hub home">
      <img class="portal-logo-image" src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="RxSource Hub">
    </a>
    <div class="portal-welcome portal-welcome-dashboard">
      <span>Testimonials</span>
      <small><?php echo hub_h($portalDisplayName ?: 'RxSource Hub'); ?></small>
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
      <a class="portal-admin-link" href="/faq.php?page=testimonials" title="Help"><i class="<?php echo hub_h(hub_nav_icon_class('help')); ?>" aria-hidden="true"></i><span>Help</span></a>
      <a class="portal-admin-link" href="/logout.php" title="Logout"><i class="<?php echo hub_h(hub_nav_icon_class('logout')); ?>" aria-hidden="true"></i><span>Logout</span></a>
      <a class="portal-website-link" href="/dashboard.php" title="Back to Dashboard">Back to Dashboard</a>
    </div>
  </header>

  <section class="portal-testimonials-intro portal-section"<?php echo $portalSectionAttrs('testimonials-intro'); ?>>
    <div class="portal-testimonials-intro-inner">
      <h1>Testimonials</h1>
      <p class="portal-testimonials-intro-subheading">Client perspectives from across RxSource partnerships</p>
      <div class="portal-testimonials-intro-copy">
        <p>
          This page will share client feedback and working perspectives from teams using RxSource services and the Hub.
          The examples below are holding content while final approved testimonials and client logos are prepared.
        </p>
      </div>
    </div>
  </section>

  <main class="portal-testimonials-list" aria-label="Client testimonials">
    <?php if (empty($testimonials)): ?>
      <section class="portal-testimonial-row portal-section"<?php echo $portalSectionAttrs('testimonial-empty'); ?>>
        <div class="portal-testimonial-inner">
          <div class="portal-testimonial-copy">
            <p>No testimonials are published yet.</p>
          </div>
        </div>
      </section>
    <?php else: ?>
      <?php foreach ($testimonials as $index => $testimonial): ?>
        <?php
          $isReversed = ($index % 2) === 1;
          $companyName = trim((string) ($testimonial['client_company_name'] ?? ''));
          $contactName = trim((string) ($testimonial['contact_name'] ?? ''));
          $jobTitle = trim((string) ($testimonial['job_title'] ?? ''));
          $logoSrc = hub_testimonial_logo_src((string) ($testimonial['client_logo'] ?? ''), 'sm');
          $initial = strtoupper(substr($companyName !== '' ? $companyName : 'R', 0, 1));
        ?>
        <section class="portal-testimonial-row portal-section <?php echo $isReversed ? 'portal-testimonial-row-reverse' : ''; ?>"<?php echo $portalSectionAttrs('testimonial'); ?>>
          <div class="portal-testimonial-inner">
            <aside class="portal-testimonial-client">
              <h2><?php echo hub_h($companyName); ?></h2>
              <?php if ($contactName !== ''): ?>
                <p><?php echo hub_h($contactName); ?></p>
              <?php endif; ?>
              <?php if ($jobTitle !== ''): ?>
                <p class="portal-testimonial-job-title"><?php echo hub_h($jobTitle); ?></p>
              <?php endif; ?>
              <div class="portal-testimonial-logo">
                <?php if ($logoSrc !== ''): ?>
                  <img src="<?php echo hub_h($logoSrc); ?>" alt="<?php echo hub_h($companyName); ?> logo">
                <?php else: ?>
                  <span><?php echo hub_h($initial); ?></span>
                <?php endif; ?>
              </div>
            </aside>
            <article class="portal-testimonial-copy">
              <blockquote><?php echo nl2br(hub_h((string) ($testimonial['testimonial_text'] ?? ''))); ?></blockquote>
            </article>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</body>
</html>
