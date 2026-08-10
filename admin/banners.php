<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/banners.php';
hub_require_super_admin();

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();

function hub_dashboard_banner_status(array $row): string {
  if ((int) ($row['archived'] ?? 0) === 1) {
    return 'Archived';
  }
  if ((int) ($row['show_on_web'] ?? 0) !== 1) {
    return 'Hidden';
  }
  return 'Published candidate';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_banner') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    try {
      $id = max(0, (int) ($_POST['id'] ?? 0));
      $name = trim((string) ($_POST['name'] ?? ''));
      if ($name === '') {
        throw new RuntimeException('Name is required.');
      }

      $existing = $id > 0 ? hub_dashboard_banner_get($id) : null;
      $imageValue = trim((string) ($existing['image'] ?? ''));
      if (!empty($_POST['remove_banner_image'])) {
        $imageValue = '';
      }
      $imageUpload = $_FILES['banner_image'] ?? null;
      if ($imageUpload && (($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $uploadError = null;
        $uploadedFilename = hub_image_upload($imageUpload, 'dashboard_banner', hub_dashboard_banner_base_name($name), $uploadError);
        if ($uploadedFilename === null) {
          throw new RuntimeException($uploadError ?: 'Unable to upload dashboard banner.');
        }
        $imageValue = $uploadedFilename;
      }

      $altText = trim((string) ($_POST['alt_text'] ?? ''));
      if ($altText === '') {
        $altText = 'RxSource Hub';
      }

      $data = [
        ':name' => hub_image_slug($name),
        ':image' => $imageValue,
        ':alt_text' => $altText,
        ':sort' => (int) ($_POST['sort'] ?? 100),
        ':show_on_web' => !empty($_POST['show_on_web']) ? 1 : 0,
        ':archived' => !empty($_POST['archived']) ? 1 : 0,
      ];

      if ($id > 0) {
        $data[':id'] = $id;
        $stmt = $pdo->prepare(
          'UPDATE hub_dashboard_banner
           SET name = :name,
               image = :image,
               alt_text = :alt_text,
               sort = :sort,
               show_on_web = :show_on_web,
               archived = :archived,
               modified = NOW()
           WHERE id = :id
           LIMIT 1'
        );
        $stmt->execute($data);
        hub_flash('success', 'Dashboard banner saved.');
        hub_redirect('/admin/banners.php?id=' . $id);
      }

      $stmt = $pdo->prepare(
        'INSERT INTO hub_dashboard_banner
          (name, image, alt_text, sort, show_on_web, archived, created, modified)
         VALUES
          (:name, :image, :alt_text, :sort, :show_on_web, :archived, NOW(), NOW())'
      );
      $stmt->execute($data);
      $newId = (int) $pdo->lastInsertId();
      hub_flash('success', 'Dashboard banner created.');
      hub_redirect('/admin/banners.php?id=' . $newId);
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

$banners = [];
$selectedId = max(0, (int) ($_GET['id'] ?? 0));
$selected = null;
if ($DB_OK && ($pdo instanceof PDO) && hub_dashboard_banner_table_ready()) {
  $banners = hub_dashboard_banners_all();
  if ($selectedId > 0) {
    $selected = hub_dashboard_banner_get($selectedId);
  }
  if (!$selected && $banners) {
    $selected = $banners[0];
    $selectedId = (int) $selected['id'];
  }
}
if (($_GET['new'] ?? '') === '1') {
  $selectedId = 0;
  $selected = ['id' => 0, 'name' => '', 'image' => '', 'alt_text' => 'RxSource Hub', 'sort' => 100, 'show_on_web' => 1, 'archived' => 0];
}
if (!$selected) {
  $selected = ['id' => 0, 'name' => '', 'image' => '', 'alt_text' => 'RxSource Hub', 'sort' => 100, 'show_on_web' => 1, 'archived' => 0];
}
$selectedImageValue = trim((string) ($selected['image'] ?? ''));
$selectedImageThumb = hub_dashboard_banner_image_src($selectedImageValue, 'xs');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Dashboard Banners</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page content-admin-page">
  <?php echo hub_admin_header('super'); ?>
  <div class="stack">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand">General Content</p>
          <h1>Dashboard Banner</h1>
          <p class="muted">Manage dashboard banner images. If several are published, the lowest sort value is shown.</p>
        </div>
        <div class="links">
          <a href="/super.php">Back to Super</a>
          <a href="/admin/banners.php?new=1">New Banner</a>
        </div>
      </div>
      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>
      <?php if (!$DB_OK || !($pdo instanceof PDO) || !hub_dashboard_banner_table_ready()): ?>
        <div class="alert error">Dashboard banner table is not available. Run the schema installer.</div>
      <?php endif; ?>
    </div>

    <div class="card content-page-selector-card">
      <form method="get" action="/admin/banners.php" class="content-page-selector">
        <div>
          <label for="banner_id">Banner</label>
          <select id="banner_id" name="id" onchange="this.form.submit()">
            <?php if (!$banners): ?>
              <option value="0">No banners yet</option>
            <?php endif; ?>
            <?php foreach ($banners as $banner): ?>
              <option value="<?php echo (int) $banner['id']; ?>" <?php echo (int) $banner['id'] === $selectedId ? 'selected' : ''; ?>>
                <?php echo hub_h((string) $banner['name']); ?> - <?php echo hub_h(hub_dashboard_banner_status($banner)); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>

    <div class="card content-page-editor">
      <form method="post" enctype="multipart/form-data" action="/admin/banners.php<?php echo $selectedId > 0 ? '?id=' . (int) $selectedId : '?new=1'; ?>">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="save_banner">
        <input type="hidden" name="id" value="<?php echo (int) ($selected['id'] ?? 0); ?>">

        <div class="content-seo-grid">
          <div>
            <label for="banner_name">Name</label>
            <input id="banner_name" name="name" type="text" value="<?php echo hub_h((string) ($selected['name'] ?? '')); ?>" required>
          </div>
          <div>
            <label for="banner_sort">Sort</label>
            <input id="banner_sort" name="sort" type="number" step="1" value="<?php echo hub_h((string) ($selected['sort'] ?? 100)); ?>">
          </div>
          <div>
            <label for="banner_alt_text">Alt Text</label>
            <input id="banner_alt_text" name="alt_text" type="text" value="<?php echo hub_h((string) ($selected['alt_text'] ?? 'RxSource Hub')); ?>" placeholder="RxSource Hub">
          </div>
          <div></div>
          <div class="announcement-image-upload">
            <label for="banner_image">Image</label>
            <input id="banner_image" name="banner_image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
            <p class="muted">Minimum original size 3000px x 500px. Saves 3000px and 150px versions in source format and WebP.</p>
            <?php if ($selectedImageValue !== ''): ?>
              <label class="muted user-image-remove"><input type="checkbox" name="remove_banner_image" value="1"> Remove current image</label>
            <?php endif; ?>
          </div>
          <div class="announcement-image-preview dashboard-banner-preview">
            <?php if ($selectedImageThumb !== ''): ?>
              <img src="<?php echo hub_h($selectedImageThumb); ?>" alt="">
              <p class="muted"><?php echo hub_h(basename($selectedImageValue)); ?></p>
            <?php else: ?>
              <p class="muted">No image uploaded.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="content-toggle-row">
          <label class="muted content-published-toggle"><input type="checkbox" name="show_on_web" value="1" <?php echo ((int) ($selected['show_on_web'] ?? 1) === 1) ? 'checked' : ''; ?>> Show on web</label>
          <label class="muted content-published-toggle"><input type="checkbox" name="archived" value="1" <?php echo ((int) ($selected['archived'] ?? 0) === 1) ? 'checked' : ''; ?>> Archived</label>
        </div>

        <div class="content-editor-meta muted">
          <?php if (!empty($selected['modified'])): ?>Last updated <?php echo hub_h((string) $selected['modified']); ?><?php else: ?>Not saved yet<?php endif; ?>
        </div>
        <div class="links content-editor-actions">
          <button class="but1" type="submit">Save Banner</button>
          <a class="but3" href="/dashboard.php" target="_blank" rel="noopener">Preview Dashboard</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
