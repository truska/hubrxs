<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/testimonials.php';
hub_require_super_admin();

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();

function hub_testimonial_status(array $row): string {
  if ((int) ($row['archived'] ?? 0) === 1) {
    return 'Archived';
  }
  if ((int) ($row['show_on_web'] ?? 0) !== 1) {
    return 'Hidden';
  }
  return 'Published';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_testimonial') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    try {
      if (!$DB_OK || !($pdo instanceof PDO) || !hub_testimonials_table_ready()) {
        throw new RuntimeException('Testimonials table is not available.');
      }

      $id = max(0, (int) ($_POST['id'] ?? 0));
      $company = trim((string) ($_POST['client_company_name'] ?? ''));
      $text = trim((string) ($_POST['testimonial_text'] ?? ''));
      if ($company === '' || $text === '') {
        throw new RuntimeException('Company name and testimonial text are required.');
      }

      $existing = $id > 0 ? hub_testimonial_get($id) : null;
      $logoValue = trim((string) ($existing['client_logo'] ?? ''));
      if (!empty($_POST['remove_client_logo'])) {
        $logoValue = '';
      }
      $logoUpload = $_FILES['client_logo'] ?? null;
      if ($logoUpload && (($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $uploadError = null;
        $uploadedFilename = hub_image_upload($logoUpload, 'testimonial_logo', hub_testimonial_logo_base_name($company), $uploadError);
        if ($uploadedFilename === null) {
          throw new RuntimeException($uploadError ?: 'Unable to upload client logo.');
        }
        $logoValue = $uploadedFilename;
      }

      $data = [
        ':client_company_name' => $company,
        ':contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
        ':job_title' => trim((string) ($_POST['job_title'] ?? '')),
        ':client_logo' => $logoValue,
        ':testimonial_text' => $text,
        ':show_on_web' => !empty($_POST['show_on_web']) ? 1 : 0,
        ':sort' => (int) ($_POST['sort'] ?? 100),
        ':archived' => !empty($_POST['archived']) ? 1 : 0,
      ];

      if ($id > 0) {
        $data[':id'] = $id;
        $stmt = $pdo->prepare(
          'UPDATE hub_testimonial
           SET client_company_name = :client_company_name,
               contact_name = :contact_name,
               job_title = :job_title,
               client_logo = :client_logo,
               testimonial_text = :testimonial_text,
               show_on_web = :show_on_web,
               sort = :sort,
               archived = :archived,
               modified = NOW()
           WHERE id = :id
           LIMIT 1'
        );
        $stmt->execute($data);
        hub_flash('success', 'Testimonial saved.');
        hub_redirect('/admin/testimonials.php?id=' . $id);
      }

      $stmt = $pdo->prepare(
        'INSERT INTO hub_testimonial
          (client_company_name, contact_name, job_title, client_logo, testimonial_text, show_on_web, sort, archived, created, modified)
         VALUES
          (:client_company_name, :contact_name, :job_title, :client_logo, :testimonial_text, :show_on_web, :sort, :archived, NOW(), NOW())'
      );
      $stmt->execute($data);
      $newId = (int) $pdo->lastInsertId();
      hub_flash('success', 'Testimonial created.');
      hub_redirect('/admin/testimonials.php?id=' . $newId);
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

$testimonials = [];
$selectedId = max(0, (int) ($_GET['id'] ?? 0));
$selected = null;
if ($DB_OK && ($pdo instanceof PDO) && hub_testimonials_table_ready()) {
  $testimonials = hub_testimonials_all(true);
  if ($selectedId > 0) {
    $selected = hub_testimonial_get($selectedId);
  }
  if (!$selected && $testimonials) {
    $selected = $testimonials[0];
    $selectedId = (int) $selected['id'];
  }
}
if (($_GET['new'] ?? '') === '1') {
  $selectedId = 0;
  $selected = ['id' => 0, 'client_company_name' => '', 'contact_name' => '', 'job_title' => '', 'client_logo' => '', 'testimonial_text' => '', 'show_on_web' => 1, 'sort' => 100, 'archived' => 0];
}
if (!$selected) {
  $selected = ['id' => 0, 'client_company_name' => '', 'contact_name' => '', 'job_title' => '', 'client_logo' => '', 'testimonial_text' => '', 'show_on_web' => 1, 'sort' => 100, 'archived' => 0];
}
$selectedLogoValue = trim((string) ($selected['client_logo'] ?? ''));
$selectedLogoThumb = hub_testimonial_logo_src($selectedLogoValue, 'xs');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Testimonials</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page content-admin-page">
  <?php echo hub_admin_header('super'); ?>
  <div class="stack">
    <div class="card">
      <div class="flex">
        <div>
          <p class="brand">General Content</p>
          <h1>Testimonials</h1>
          <p class="muted">Manage client testimonials shown on the Hub testimonials page.</p>
        </div>
        <div class="links">
          <a href="/super.php">Back to Super</a>
          <a href="/admin/testimonials.php?new=1">New Testimonial</a>
        </div>
      </div>
      <?php foreach ($messages as $msg): ?>
        <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?>
        <div class="alert error"><?php echo hub_h($error); ?></div>
      <?php endif; ?>
      <?php if (!$DB_OK || !($pdo instanceof PDO) || !hub_testimonials_table_ready()): ?>
        <div class="alert error">Testimonials table is not available. Run the schema installer.</div>
      <?php endif; ?>
    </div>

    <div class="card content-page-selector-card">
      <form method="get" action="/admin/testimonials.php" class="content-page-selector">
        <div>
          <label for="testimonial_id">Testimonial</label>
          <select id="testimonial_id" name="id" onchange="this.form.submit()">
            <?php if (!$testimonials): ?>
              <option value="0">No testimonials yet</option>
            <?php endif; ?>
            <?php foreach ($testimonials as $testimonial): ?>
              <option value="<?php echo (int) $testimonial['id']; ?>" <?php echo (int) $testimonial['id'] === $selectedId ? 'selected' : ''; ?>>
                <?php echo hub_h((string) $testimonial['client_company_name']); ?> - <?php echo hub_h(hub_testimonial_status($testimonial)); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>

    <div class="card content-page-editor">
      <form method="post" enctype="multipart/form-data" action="/admin/testimonials.php<?php echo $selectedId > 0 ? '?id=' . (int) $selectedId : '?new=1'; ?>">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="save_testimonial">
        <input type="hidden" name="id" value="<?php echo (int) ($selected['id'] ?? 0); ?>">

        <div class="content-seo-grid">
          <div>
            <label for="client_company_name">Client Company Name</label>
            <input id="client_company_name" name="client_company_name" type="text" value="<?php echo hub_h((string) ($selected['client_company_name'] ?? '')); ?>" required>
          </div>
          <div>
            <label for="testimonial_sort">Sort</label>
            <input id="testimonial_sort" name="sort" type="number" step="1" value="<?php echo hub_h((string) ($selected['sort'] ?? 100)); ?>">
          </div>
          <div>
            <label for="contact_name">Contact Name</label>
            <input id="contact_name" name="contact_name" type="text" value="<?php echo hub_h((string) ($selected['contact_name'] ?? '')); ?>">
          </div>
          <div>
            <label for="job_title">Job Title</label>
            <input id="job_title" name="job_title" type="text" value="<?php echo hub_h((string) ($selected['job_title'] ?? '')); ?>">
          </div>
          <div class="announcement-image-upload">
            <label for="client_logo">Client Logo</label>
            <input id="client_logo" name="client_logo" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
            <p class="muted">Optional. Saves 360px and 150px versions in source format and WebP.</p>
            <?php if ($selectedLogoValue !== ''): ?>
              <label class="muted user-image-remove"><input type="checkbox" name="remove_client_logo" value="1"> Remove current logo</label>
            <?php endif; ?>
          </div>
          <div class="announcement-image-preview testimonial-logo-preview">
            <?php if ($selectedLogoThumb !== ''): ?>
              <img src="<?php echo hub_h($selectedLogoThumb); ?>" alt="">
              <p class="muted"><?php echo hub_h(basename($selectedLogoValue)); ?></p>
            <?php else: ?>
              <p class="muted">No logo uploaded. The frontend will show an initial placeholder.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="admin-form-wide">
          <label for="testimonial_text">Testimonial Text</label>
          <textarea id="testimonial_text" name="testimonial_text" rows="8" required><?php echo hub_h((string) ($selected['testimonial_text'] ?? '')); ?></textarea>
        </div>

        <div class="content-toggle-row">
          <label class="muted content-published-toggle"><input type="checkbox" name="show_on_web" value="1" <?php echo ((int) ($selected['show_on_web'] ?? 1) === 1) ? 'checked' : ''; ?>> Show on web</label>
          <label class="muted content-published-toggle"><input type="checkbox" name="archived" value="1" <?php echo ((int) ($selected['archived'] ?? 0) === 1) ? 'checked' : ''; ?>> Archived</label>
        </div>

        <div class="content-editor-meta muted">
          <?php if (!empty($selected['modified'])): ?>Last updated <?php echo hub_h((string) $selected['modified']); ?><?php else: ?>Not saved yet<?php endif; ?>
        </div>
        <div class="links content-editor-actions">
          <button class="but1" type="submit">Save Testimonial</button>
          <a class="but3" href="/testimonials.php" target="_blank" rel="noopener">Preview Testimonials</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
