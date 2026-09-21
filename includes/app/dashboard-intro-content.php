<?php
require_once __DIR__ . '/content.php';
$dashboardIntro = hub_content_page_get('dashboard_intro');
if (empty($dashboardIntro['published'])) return;
?>
<section class="portal-dashboard-intro portal-section" aria-label="<?php echo hub_h((string) ($dashboardIntro['title'] ?? 'Introduction to RX Hub')); ?>"<?php echo $portalSectionAttrs('dashboard-intro'); ?>>
  <div class="portal-dashboard-intro-inner">
    <h2><?php echo hub_h((string) ($dashboardIntro['title'] ?? 'Introduction to RX Hub')); ?></h2>
    <div class="portal-dashboard-intro-copy">
      <?php echo hub_content_render_text((string) ($dashboardIntro['body'] ?? '')); ?>
    </div>
  </div>
</section>
