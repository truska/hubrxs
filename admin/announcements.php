<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/announcements.php';
hub_require_super_admin();

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();

function hub_announcement_datetime_parts(?string $value): array {
  $value = trim((string) $value);
  if ($value === '') {
    return ['date' => '', 'hour' => '', 'minute' => ''];
  }
  $time = strtotime($value);
  if (!$time) {
    return ['date' => '', 'hour' => '', 'minute' => ''];
  }
  $minute = (int) date('i', $time);
  $minute = (int) floor($minute / 15) * 15;
  return [
    'date' => date('Y-m-d', $time),
    'hour' => date('H', $time),
    'minute' => sprintf('%02d', $minute),
  ];
}

function hub_announcement_input_datetime_from_parts(string $prefix): ?string {
  $date = trim((string) ($_POST[$prefix . '_date'] ?? ''));
  if ($date === '') {
    return null;
  }
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    return null;
  }
  $hour = (int) ($_POST[$prefix . '_hour'] ?? 0);
  $minute = (int) ($_POST[$prefix . '_minute'] ?? 0);
  $hour = max(0, min(23, $hour));
  $minute = in_array($minute, [0, 15, 30, 45], true) ? $minute : 0;
  return sprintf('%s %02d:%02d:00', $date, $hour, $minute);
}

function hub_announcement_status(array $row): string {
  if ((int) ($row['archived'] ?? 0) === 1) {
    return 'Archived';
  }
  if ((int) ($row['show_on_web'] ?? 0) !== 1) {
    return 'Hidden';
  }
  $now = time();
  $from = !empty($row['show_from']) ? strtotime((string) $row['show_from']) : null;
  $to = !empty($row['show_to']) ? strtotime((string) $row['show_to']) : null;
  if ($from && $from > $now) {
    return 'Scheduled';
  }
  if ($to && $to < $now) {
    return 'Expired';
  }
  return 'Live candidate';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_announcement') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    try {
      $id = max(0, (int) ($_POST['id'] ?? 0));
      $name = trim((string) ($_POST['name'] ?? ''));
      $heading = trim((string) ($_POST['heading'] ?? ''));
      if ($heading === '') {
        throw new RuntimeException('Heading is required.');
      }
      if ($name === '') {
        $name = hub_content_slugify($heading);
      }

      $existing = $id > 0 ? hub_announcement_get($id) : null;
      $imageValue = trim((string) ($existing['image_url'] ?? ''));
      if (!empty($_POST['remove_announcement_image'])) {
        $imageValue = '';
      }
      $imageUpload = $_FILES['announcement_image'] ?? null;
      if ($imageUpload && (($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $uploadError = null;
        $uploadedFilename = hub_image_upload($imageUpload, 'announcement', hub_announcement_image_base_name($name), $uploadError);
        if ($uploadedFilename === null) {
          throw new RuntimeException($uploadError ?: 'Unable to upload announcement image.');
        }
        $imageValue = $uploadedFilename;
      }

      $data = [
        ':name' => $name,
        ':heading' => $heading,
        ':subheading' => trim((string) ($_POST['subheading'] ?? '')),
        ':body_html' => hub_content_sanitize_html((string) ($_POST['body_html'] ?? '')),
        ':image_url' => $imageValue,
        ':link_url' => trim((string) ($_POST['link_url'] ?? '')),
        ':link_label' => trim((string) ($_POST['link_label'] ?? '')),
        ':show_from' => hub_announcement_input_datetime_from_parts('show_from'),
        ':show_to' => hub_announcement_input_datetime_from_parts('show_to'),
        ':sort' => (int) ($_POST['sort'] ?? 100),
        ':show_on_web' => !empty($_POST['show_on_web']) ? 1 : 0,
        ':archived' => !empty($_POST['archived']) ? 1 : 0,
      ];

      if ($id > 0) {
        $data[':id'] = $id;
        $stmt = $pdo->prepare(
          'UPDATE hub_announcement
           SET name = :name,
               heading = :heading,
               subheading = :subheading,
               body_html = :body_html,
               image_url = :image_url,
               link_url = :link_url,
               link_label = :link_label,
               show_from = :show_from,
               show_to = :show_to,
               sort = :sort,
               show_on_web = :show_on_web,
               archived = :archived,
               modified = NOW()
           WHERE id = :id
           LIMIT 1'
        );
        $stmt->execute($data);
        hub_flash('success', 'Announcement saved.');
        hub_redirect('/admin/announcements.php?id=' . $id);
      }

      $stmt = $pdo->prepare(
        'INSERT INTO hub_announcement
          (name, heading, subheading, body_html, image_url, link_url, link_label, show_from, show_to, sort, show_on_web, archived, created, modified)
         VALUES
          (:name, :heading, :subheading, :body_html, :image_url, :link_url, :link_label, :show_from, :show_to, :sort, :show_on_web, :archived, NOW(), NOW())'
      );
      $stmt->execute($data);
      $newId = (int) $pdo->lastInsertId();
      hub_flash('success', 'Announcement created.');
      hub_redirect('/admin/announcements.php?id=' . $newId);
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

$announcements = [];
$selectedId = max(0, (int) ($_GET['id'] ?? 0));
$selected = null;
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_announcement')) {
  $announcements = hub_announcements_all();
  if ($selectedId > 0) {
    $selected = hub_announcement_get($selectedId);
  }
  if (!$selected && $announcements) {
    $selected = $announcements[0];
    $selectedId = (int) $selected['id'];
  }
}
if (($_GET['new'] ?? '') === '1') {
  $selectedId = 0;
  $selected = [
    'id' => 0,
    'name' => '',
    'heading' => '',
    'subheading' => '',
    'body_html' => '',
    'image_url' => '',
    'link_url' => '',
    'link_label' => '',
    'show_from' => '',
    'show_to' => '',
    'sort' => 100,
    'show_on_web' => 1,
    'archived' => 0,
  ];
}
if (!$selected) {
  $selected = [
    'id' => 0,
    'name' => '',
    'heading' => '',
    'subheading' => '',
    'body_html' => '',
    'image_url' => '',
    'link_url' => '',
    'link_label' => '',
    'show_from' => '',
    'show_to' => '',
    'sort' => 100,
    'show_on_web' => 1,
    'archived' => 0,
  ];
}
$selectedImageValue = trim((string) ($selected['image_url'] ?? ''));
$selectedImageThumb = hub_announcement_image_src($selectedImageValue, 'xs');
$showFromParts = hub_announcement_datetime_parts($selected['show_from'] ?? '');
$showToParts = hub_announcement_datetime_parts($selected['show_to'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Announcements</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page content-admin-page">
  <?php echo hub_admin_header('super'); ?>
  <div class="stack">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand">General Content</p>
          <h1>Announcement Bar</h1>
          <p class="muted">Manage dashboard announcements. If several are valid, the lowest sort value wins, then earliest start date, then oldest record.</p>
        </div>
        <div class="links">
          <a href="/super.php">Back to Super</a>
          <a href="/admin/announcements.php?new=1">New Announcement</a>
        </div>
      </div>
      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>
      <?php if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_announcement')): ?>
        <div class="alert error">Announcement table is not available. Run the schema installer.</div>
      <?php endif; ?>
    </div>

    <div class="card content-page-selector-card">
      <form method="get" action="/admin/announcements.php" class="content-page-selector">
        <div>
          <label for="announcement_id">Announcement</label>
          <select id="announcement_id" name="id" onchange="this.form.submit()">
            <?php if (!$announcements): ?>
              <option value="0">No announcements yet</option>
            <?php endif; ?>
            <?php foreach ($announcements as $announcement): ?>
              <option value="<?php echo (int) $announcement['id']; ?>" <?php echo (int) $announcement['id'] === $selectedId ? 'selected' : ''; ?>>
                <?php echo hub_h((string) $announcement['heading']); ?> - <?php echo hub_h(hub_announcement_status($announcement)); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>

    <div class="card content-page-editor">
      <form method="post" enctype="multipart/form-data" action="/admin/announcements.php<?php echo $selectedId > 0 ? '?id=' . (int) $selectedId : '?new=1'; ?>">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="save_announcement">
        <input type="hidden" name="id" value="<?php echo (int) ($selected['id'] ?? 0); ?>">

        <div class="content-seo-grid">
          <div>
            <label for="announcement_name">Internal Name</label>
            <input id="announcement_name" name="name" type="text" value="<?php echo hub_h((string) ($selected['name'] ?? '')); ?>" placeholder="Auto-created from heading if blank">
          </div>
          <div>
            <label for="announcement_sort">Sort</label>
            <input id="announcement_sort" name="sort" type="number" step="1" value="<?php echo hub_h((string) ($selected['sort'] ?? 100)); ?>">
          </div>
          <div>
            <label for="announcement_heading">Heading</label>
            <input id="announcement_heading" name="heading" type="text" value="<?php echo hub_h((string) ($selected['heading'] ?? '')); ?>" required>
          </div>
          <div>
            <label for="announcement_subheading">Subheading</label>
            <input id="announcement_subheading" name="subheading" type="text" value="<?php echo hub_h((string) ($selected['subheading'] ?? '')); ?>">
          </div>
          <div class="announcement-image-upload">
            <label for="announcement_image">Image</label>
            <input id="announcement_image" name="announcement_image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
            <p class="muted">Saves 300px and 150px versions in source format and WebP.</p>
            <?php if ($selectedImageValue !== ''): ?>
              <label class="muted user-image-remove"><input type="checkbox" name="remove_announcement_image" value="1"> Remove current image</label>
            <?php endif; ?>
          </div>
          <div class="announcement-image-preview">
            <?php if ($selectedImageThumb !== ''): ?>
              <img src="<?php echo hub_h($selectedImageThumb); ?>" alt="">
              <p class="muted"><?php echo hub_h(basename($selectedImageValue)); ?></p>
            <?php else: ?>
              <p class="muted">No image uploaded.</p>
            <?php endif; ?>
          </div>
          <div>
            <label for="announcement_link_label">Link Label</label>
            <input id="announcement_link_label" name="link_label" type="text" value="<?php echo hub_h((string) ($selected['link_label'] ?? '')); ?>">
          </div>
          <div>
            <label for="announcement_link_url">Link URL</label>
            <input id="announcement_link_url" name="link_url" type="text" value="<?php echo hub_h((string) ($selected['link_url'] ?? '')); ?>">
          </div>
          <div>
            <label for="show_from_date">Show From</label>
            <div class="date-time-parts">
              <input id="show_from_date" name="show_from_date" type="date" value="<?php echo hub_h($showFromParts['date']); ?>">
              <select name="show_from_hour" aria-label="Show from hour">
                <?php for ($hour = 0; $hour < 24; $hour++): $hourValue = sprintf('%02d', $hour); ?>
                  <option value="<?php echo $hourValue; ?>" <?php echo $showFromParts['hour'] === $hourValue ? 'selected' : ''; ?>><?php echo $hourValue; ?></option>
                <?php endfor; ?>
              </select>
              <select name="show_from_minute" aria-label="Show from minute">
                <?php foreach (['00', '15', '30', '45'] as $minuteValue): ?>
                  <option value="<?php echo $minuteValue; ?>" <?php echo $showFromParts['minute'] === $minuteValue ? 'selected' : ''; ?>><?php echo $minuteValue; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div>
            <label for="show_to_date">Show To</label>
            <div class="date-time-parts">
              <input id="show_to_date" name="show_to_date" type="date" value="<?php echo hub_h($showToParts['date']); ?>">
              <select name="show_to_hour" aria-label="Show to hour">
                <?php for ($hour = 0; $hour < 24; $hour++): $hourValue = sprintf('%02d', $hour); ?>
                  <option value="<?php echo $hourValue; ?>" <?php echo $showToParts['hour'] === $hourValue ? 'selected' : ''; ?>><?php echo $hourValue; ?></option>
                <?php endfor; ?>
              </select>
              <select name="show_to_minute" aria-label="Show to minute">
                <?php foreach (['00', '15', '30', '45'] as $minuteValue): ?>
                  <option value="<?php echo $minuteValue; ?>" <?php echo $showToParts['minute'] === $minuteValue ? 'selected' : ''; ?>><?php echo $minuteValue; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <div class="content-editor-body">
          <label for="announcement_body_html">Body HTML</label>
          <textarea id="announcement_body_html" class="content-html-editor" name="body_html" rows="12" data-rich-text="limited-html"><?php echo hub_h((string) ($selected['body_html'] ?? '')); ?></textarea>
          <p class="muted rich-editor-fallback" data-rich-editor-fallback>HTML editor not loaded. You can still edit HTML in this field.</p>
        </div>

        <div class="content-toggle-row">
          <label class="muted content-published-toggle"><input type="checkbox" name="show_on_web" value="1" <?php echo ((int) ($selected['show_on_web'] ?? 1) === 1) ? 'checked' : ''; ?>> Show on web</label>
          <label class="muted content-published-toggle"><input type="checkbox" name="archived" value="1" <?php echo ((int) ($selected['archived'] ?? 0) === 1) ? 'checked' : ''; ?>> Archived</label>
        </div>

        <div class="content-editor-meta muted">
          <?php if (!empty($selected['modified'])): ?>Last updated <?php echo hub_h((string) $selected['modified']); ?><?php else: ?>Not saved yet<?php endif; ?>
        </div>
        <div class="links content-editor-actions">
          <button class="but1" type="submit">Save Announcement</button>
          <a class="but3" href="/dashboard.php" target="_blank" rel="noopener">Preview Dashboard</a>
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
        height: 420,
        min_height: 260,
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
