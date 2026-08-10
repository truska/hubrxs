<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/content.php';
hub_require_super_admin();

global $pdo, $DB_OK;

$currentUser = hub_current_user();
$error = null;
$messages = hub_flash_messages();
$pageDefs = hub_content_page_keys();
$selectedKey = (string) ($_GET['page_key'] ?? array_key_first($pageDefs));
if (!isset($pageDefs[$selectedKey])) {
  $selectedKey = array_key_first($pageDefs);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_policy_page') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    $postedKey = (string) ($_POST['page_key'] ?? '');
    if (!isset($pageDefs[$postedKey])) {
      $error = 'Unknown content page.';
    } else {
      try {
        hub_content_page_save(
          $postedKey,
          [
            'title' => (string) ($_POST['title'] ?? ''),
            'slug' => (string) ($_POST['slug'] ?? ''),
            'meta_title' => (string) ($_POST['meta_title'] ?? ''),
            'meta_description' => (string) ($_POST['meta_description'] ?? ''),
            'canonical_url' => (string) ($_POST['canonical_url'] ?? ''),
            'robots' => (string) ($_POST['robots'] ?? 'index,follow'),
            'body' => (string) ($_POST['body'] ?? ''),
            'published' => !empty($_POST['published']),
          ],
          isset($currentUser['id']) ? (int) $currentUser['id'] : null
        );
        hub_flash('success', 'Content page saved.');
        hub_redirect('/admin/content.php?page_key=' . urlencode($postedKey));
      } catch (Throwable $e) {
        $error = $e->getMessage();
      }
    }
  }
}

$pageRows = [];
foreach ($pageDefs as $key => $def) {
  $pageRows[$key] = hub_content_page_get($key);
}
$selectedPage = $pageRows[$selectedKey] ?? hub_content_page_get($selectedKey);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | General Content</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page content-admin-page">
  <?php echo hub_admin_header('super'); ?>
  <div class="stack">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand">General Content</p>
          <h1>General Content</h1>
          <p class="muted">Manage policy pages now. Announcements, testimonials and image-led content can sit here next.</p>
        </div>
        <div class="links">
          <a href="/super.php">Back to Super</a>
          <a href="/dashboard.php">Dashboard</a>
        </div>
      </div>
      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>
      <?php if (!$DB_OK || !($pdo instanceof PDO) || !hub_content_page_table_ready()): ?>
        <div class="alert error">Content page table is not available. Run the schema installer.</div>
      <?php endif; ?>
    </div>

    <div class="card content-page-selector-card">
      <form method="get" action="/admin/content.php" class="content-page-selector">
        <div>
          <label for="page_key">Content Page</label>
          <select id="page_key" name="page_key" onchange="this.form.submit()">
            <?php foreach ($pageDefs as $key => $def): ?>
              <?php $row = $pageRows[$key] ?? []; ?>
              <option value="<?php echo hub_h($key); ?>" <?php echo $selectedKey === $key ? 'selected' : ''; ?>>
                <?php echo hub_h((string) ($row['title'] ?? $def['title'])); ?><?php echo ((int) ($row['published'] ?? 1) === 1) ? '' : ' - Hidden'; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>

    <div class="card content-page-editor">
      <form method="post" action="/admin/content.php?page_key=<?php echo hub_h(urlencode($selectedKey)); ?>">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="save_policy_page">
        <input type="hidden" name="page_key" value="<?php echo hub_h($selectedKey); ?>">

        <div class="content-seo-grid">
          <div>
            <label for="content_title">Page Title</label>
            <input id="content_title" name="title" type="text" value="<?php echo hub_h((string) ($selectedPage['title'] ?? '')); ?>" required>
          </div>
          <div>
            <label for="content_slug">Slug</label>
            <input id="content_slug" name="slug" type="text" value="<?php echo hub_h((string) ($selectedPage['slug'] ?? '')); ?>" placeholder="privacy-policy">
          </div>
          <div>
            <label for="content_meta_title">Browser / Search Title</label>
            <input id="content_meta_title" name="meta_title" type="text" value="<?php echo hub_h((string) ($selectedPage['meta_title'] ?? '')); ?>">
          </div>
          <div>
            <label for="content_robots">Search Robots</label>
            <select id="content_robots" name="robots">
              <?php $robots = (string) ($selectedPage['robots'] ?? 'index,follow'); ?>
              <option value="index,follow" <?php echo $robots === 'index,follow' ? 'selected' : ''; ?>>Index, follow</option>
              <option value="noindex,follow" <?php echo $robots === 'noindex,follow' ? 'selected' : ''; ?>>No index, follow</option>
            </select>
          </div>
          <div class="content-meta-description">
            <label for="content_meta_description">Meta Description</label>
            <textarea id="content_meta_description" name="meta_description" rows="3"><?php echo hub_h((string) ($selectedPage['meta_description'] ?? '')); ?></textarea>
          </div>
          <div class="content-meta-description">
            <label for="content_canonical_url">Canonical URL</label>
            <input id="content_canonical_url" name="canonical_url" type="url" value="<?php echo hub_h((string) ($selectedPage['canonical_url'] ?? '')); ?>" placeholder="Leave blank unless a specific canonical URL is needed">
          </div>
        </div>

        <div class="content-editor-body">
          <label for="content_body">Page Text</label>
          <textarea id="content_body" class="content-html-editor" name="body" rows="18" data-rich-text="limited-html"><?php echo hub_h((string) ($selectedPage['body'] ?? '')); ?></textarea>
          <p class="muted rich-editor-fallback" data-rich-editor-fallback>HTML editor not loaded. You can still edit HTML in this field.</p>
        </div>
        <label class="muted content-published-toggle"><input type="checkbox" name="published" value="1" <?php echo ((int) ($selectedPage['published'] ?? 1) === 1) ? 'checked' : ''; ?>> Published</label>
        <div class="content-editor-meta muted">
          <?php if (!empty($selectedPage['updated_at'])): ?>Last updated <?php echo hub_h((string) $selectedPage['updated_at']); ?><?php else: ?>Not updated yet<?php endif; ?>
        </div>
        <div class="links content-editor-actions">
          <button class="but1" type="submit">Save Page</button>
          <a class="but3" href="<?php echo hub_h((string) ($pageDefs[$selectedKey]['path'] ?? '#')); ?>" target="_blank" rel="noopener">Preview</a>
        </div>
      </form>
    </div>
  </div>
  <script src="/js/tinymce/tinymce.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var fallback = document.querySelector('[data-rich-editor-fallback]');
      if (!window.tinymce) {
        if (fallback) fallback.style.display = 'block';
        return;
      }
      if (fallback) fallback.style.display = 'none';

      window.tinymce.init({
        selector: 'textarea[data-rich-text="limited-html"]',
        base_url: '/js/tinymce',
        suffix: '.min',
        license_key: 'gpl',
        menubar: false,
        branding: false,
        promotion: false,
        height: 520,
        min_height: 360,
        plugins: 'autoresize code link lists wordcount',
        toolbar: 'undo redo | blocks | bold italic | bullist numlist blockquote | link unlink | removeformat | code',
        block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
        valid_elements: 'p,br,h2,h3,h4,strong/b,em/i,ul,ol,li,blockquote,hr,a[href|title|target|rel]',
        invalid_elements: 'script,style,iframe,img,form,input,button,textarea,select,object,embed',
        forced_root_block: 'p',
        convert_urls: false,
        link_assume_external_targets: 'https',
        target_list: [
          { title: 'Same window', value: '' },
          { title: 'New window', value: '_blank' }
        ],
        content_style: 'body{font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#26333b;} h2,h3,h4{margin:1.2em 0 .5em;} a{color:#c60012;} blockquote{border-left:4px solid #d8dde1;margin:1em 0;padding:.2em 0 .2em 1em;color:#53636c;}'
      });
    });
  </script>
</body>
</html>
