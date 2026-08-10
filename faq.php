<?php
require_once __DIR__ . '/includes/app/faq.php';
require_once __DIR__ . '/includes/app/admin_layout.php';
hub_require_login();

global $pdo, $DB_OK;

$pageFilter = hub_faq_normalise_page_key((string) ($_GET['page'] ?? ''));
$allowedSections = hub_faq_allowed_sections(hub_current_user());
$faqs = [];
$error = null;

if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_faq')) {
  $error = 'FAQ table is not available yet.';
} else {
  $sectionPlaceholders = [];
  $params = [];
  foreach ($allowedSections as $index => $section) {
    $key = ':section' . $index;
    $sectionPlaceholders[] = $key;
    $params[$key] = $section;
  }
  $where = 'show_on_web = 1 AND archived = 0 AND section IN (' . implode(',', $sectionPlaceholders) . ')';
  if ($pageFilter !== '') {
    $where .= ' AND (page_key IS NULL OR page_key = "" OR page_key = :page_key)';
    $params[':page_key'] = $pageFilter;
  }
  $stmt = $pdo->prepare(
    'SELECT id, section, page_key, question, answer_html, sort
     FROM hub_faq
     WHERE ' . $where . '
     ORDER BY section ASC, COALESCE(NULLIF(page_key, ""), "zzzz_general") ASC, sort ASC, question ASC, id ASC'
  );
  $stmt->execute($params);
  $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$title = $pageFilter !== '' ? 'Help: ' . hub_faq_page_label($pageFilter) : 'Help and FAQ';
$isAdminShell = hub_role_at_least('manager');
$user = hub_current_user();
$portalDisplayName = trim((string) ($user['display_name'] ?? ''));
if ($portalDisplayName === '') {
  $portalDisplayName = trim((string) ($user['email'] ?? ''));
  if (strpos($portalDisplayName, '@') !== false) {
    $portalDisplayName = strstr($portalDisplayName, '@', true) ?: $portalDisplayName;
  }
}
$customerLabel = '';
$effectiveCustomerId = hub_effective_customer_id($user);
if ($effectiveCustomerId && $DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
  $stmtCustomer = $pdo->prepare('SELECT code, name FROM hub_customer WHERE id = :id LIMIT 1');
  $stmtCustomer->execute([':id' => (int) $effectiveCustomerId]);
  $customer = $stmtCustomer->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($customer) {
    $name = trim((string) ($customer['name'] ?? ''));
    $code = trim((string) ($customer['code'] ?? ''));
    $customerLabel = $name !== '' ? $name : $code;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | FAQ</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="<?php echo $isAdminShell ? 'admin-page faq-page' : 'portal-page faq-page'; ?>">
  <?php if ($isAdminShell): ?>
    <?php echo hub_admin_header('help'); ?>
  <?php else: ?>
    <header class="portal-header">
      <a class="portal-logo" href="/dashboard.php" aria-label="RxSource Hub home">
        <img class="portal-logo-image" src="<?php echo hub_h(hub_site_logo_url()); ?>" alt="RxSource Hub">
      </a>
      <div class="portal-welcome">
        <?php echo hub_h($portalDisplayName ?: 'User'); ?><?php echo $customerLabel !== '' ? ' [' . hub_h($customerLabel) . ']' : ''; ?>
      </div>
      <div class="portal-header-actions">
        <a class="portal-website-link" href="/dashboard.php">Dashboard</a>
        <a class="portal-website-link" href="/logout.php">Logout</a>
      </div>
    </header>
  <?php endif; ?>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Help</p>
            <h1><?php echo hub_h($title); ?></h1>
            <p class="muted">FAQs shown here are filtered for your current user role<?php echo $pageFilter !== '' ? ' and this page' : ''; ?>.</p>
          </div>
          <div class="links">
            <a href="/dashboard.php">Dashboard</a>
            <?php if (hub_is_super_admin()): ?>
              <a href="/admin/faqs.php">Edit FAQs</a>
            <?php endif; ?>
          </div>
        </div>
        <form method="get" action="/faq.php" class="filters-grid">
          <div>
            <label for="page">Page / report</label>
            <select id="page" name="page" onchange="this.form.submit()">
              <?php foreach (hub_faq_page_options() as $key => $label): ?>
                <option value="<?php echo hub_h($key); ?>" <?php echo $pageFilter === $key ? 'selected' : ''; ?>><?php echo hub_h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="links" style="align-items:flex-end; justify-content:flex-start;">
            <button type="submit">Filter</button>
            <a href="/faq.php">Clear</a>
          </div>
        </form>
      </div>

      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php elseif (empty($faqs)): ?>
        <div class="alert info">No FAQ items found for this view yet.</div>
      <?php else: ?>
        <div class="card faq-card">
          <div class="faq-accordion" data-faq-accordion>
            <?php foreach ($faqs as $faq): ?>
              <?php
                $faqId = (int) $faq['id'];
                $panelId = 'faq-panel-' . $faqId;
                $buttonId = 'faq-button-' . $faqId;
                $pageKey = (string) ($faq['page_key'] ?? '');
              ?>
              <section class="faq-item">
                <h2 class="faq-question-heading">
                  <button id="<?php echo hub_h($buttonId); ?>" class="faq-question" type="button" aria-expanded="false" aria-controls="<?php echo hub_h($panelId); ?>" data-faq-toggle>
                    <span class="faq-question-text"><?php echo hub_h((string) $faq['question']); ?></span>
                    <span class="faq-question-meta">
                      <?php echo hub_h(hub_faq_role_label((string) $faq['section'])); ?> · <?php echo hub_h(hub_faq_page_label($pageKey)); ?>
                    </span>
                    <span class="faq-question-icon" aria-hidden="true"></span>
                  </button>
                </h2>
                <div id="<?php echo hub_h($panelId); ?>" class="faq-answer" role="region" aria-labelledby="<?php echo hub_h($buttonId); ?>" hidden>
                  <div class="help-message"><?php echo (string) $faq['answer_html']; ?></div>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
    (function () {
      var accordions = document.querySelectorAll('[data-faq-accordion]');
      accordions.forEach(function (accordion) {
        accordion.addEventListener('click', function (event) {
          var button = event.target.closest('[data-faq-toggle]');
          if (!button || !accordion.contains(button)) return;

          var panel = document.getElementById(button.getAttribute('aria-controls'));
          if (!panel) return;

          var isOpen = button.getAttribute('aria-expanded') === 'true';
          accordion.querySelectorAll('[data-faq-toggle][aria-expanded="true"]').forEach(function (openButton) {
            var openPanel = document.getElementById(openButton.getAttribute('aria-controls'));
            openButton.setAttribute('aria-expanded', 'false');
            var openItem = openButton.closest('.faq-item');
            if (openItem) openItem.classList.remove('is-open');
            if (openPanel) openPanel.hidden = true;
          });

          if (!isOpen) {
            button.setAttribute('aria-expanded', 'true');
            var item = button.closest('.faq-item');
            if (item) item.classList.add('is-open');
            panel.hidden = false;
          }
        });
      });
    })();
  </script>
</body>
</html>
