<?php
require_once __DIR__ . '/general_content.php';
$dashboardBlocks = hub_general_content_all(true);
if (!$dashboardBlocks) return;
?>
<?php foreach ($dashboardBlocks as $dashboardIntro): ?>
<section class="portal-dashboard-intro portal-section" aria-label="<?php echo hub_h((string) ($dashboardIntro['title'] ?? 'Dashboard content')); ?>"<?php echo $portalSectionAttrs('dashboard-intro'); ?>>
  <div class="portal-dashboard-intro-inner">
    <h2><?php echo hub_h((string) ($dashboardIntro['title'] ?? 'Introduction to RX Hub')); ?></h2>
    <div class="portal-dashboard-intro-copy">
      <?php echo hub_content_render_text((string) ($dashboardIntro['body'] ?? '')); ?>
    </div>
  </div>
</section>
<?php endforeach; ?>
