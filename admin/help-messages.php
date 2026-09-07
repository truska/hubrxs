<?php
require_once __DIR__ . '/../includes/app/help.php';
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_admin();

global $pdo, $DB_OK;

$error = null;
$editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_help_message')) {
    $error = 'Help message table is not available.';
  } else {
    $name = trim((string) ($_POST['name'] ?? ''));
    $heading = trim((string) ($_POST['heading'] ?? ''));
    $context = hub_help_admin_choice((string) ($_POST['context'] ?? 'info'), ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'], 'info');
    $icon = hub_help_icon_for_context($context);
    $messageHtml = hub_content_sanitize_html((string) ($_POST['message_html'] ?? ''));
    $showOnWeb = !empty($_POST['show_on_web']) ? 1 : 0;
    $archived = !empty($_POST['archived']) ? 1 : 0;

    if ($name === '' || $messageHtml === '') {
      $error = 'Name and message are required.';
    } else {
      try {
        if ($editId > 0) {
          $stmt = $pdo->prepare(
            'UPDATE hub_help_message
             SET name = :name, heading = :heading, context = :context, icon = :icon, message_html = :message_html, show_on_web = :show_on_web, archived = :archived, modified = NOW()
             WHERE id = :id'
          );
          $stmt->execute([
            ':id' => $editId,
            ':name' => $name,
            ':heading' => $heading !== '' ? $heading : null,
            ':context' => $context,
            ':icon' => $icon,
            ':message_html' => $messageHtml,
            ':show_on_web' => $showOnWeb,
            ':archived' => $archived,
          ]);
          hub_flash('success', 'Help message updated.');
        } else {
          $stmt = $pdo->prepare(
            'INSERT INTO hub_help_message (name, heading, context, icon, message_html, show_on_web, archived, created, modified)
             VALUES (:name, :heading, :context, :icon, :message_html, :show_on_web, :archived, NOW(), NOW())'
          );
          $stmt->execute([
            ':name' => $name,
            ':heading' => $heading !== '' ? $heading : null,
            ':context' => $context,
            ':icon' => $icon,
            ':message_html' => $messageHtml,
            ':show_on_web' => $showOnWeb,
            ':archived' => $archived,
          ]);
          hub_flash('success', 'Help message added.');
        }
        hub_redirect('/admin/help-messages.php');
      } catch (PDOException $e) {
        $error = 'Unable to save help message. The name may already exist.';
      }
    }
  }
}

function hub_help_admin_choice(string $value, array $allowed, string $default): string {
  $value = strtolower(trim($value));
  return in_array($value, $allowed, true) ? $value : $default;
}

if ($editId > 0 && $DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_help_message')) {
  $stmt = $pdo->prepare('SELECT * FROM hub_help_message WHERE id = :id LIMIT 1');
  $stmt->execute([':id' => $editId]);
  $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$messages = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_help_message')) {
  $stmt = $pdo->query('SELECT * FROM hub_help_message ORDER BY archived ASC, name ASC, id ASC');
  $messages = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

$flashes = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Help Messages</title>
  <?php echo hub_fontawesome_css(); ?>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
  <?php echo hub_admin_header('admin'); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Admin</p>
            <h1><?php echo $editing ? 'Edit help message' : 'Help messages'; ?></h1>
            <p class="muted">Manage contextual help popups shown beside buttons and actions.</p>
          </div>
          <div class="links">
            <a href="/admin.php">Back to admin</a>
            <a href="/admin/import-sources.php">Import sources</a>
            <a href="/dashboard.php">Dashboard</a>
          </div>
        </div>

        <?php foreach ($flashes as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/help-messages.php">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <input type="hidden" name="id" value="<?php echo $editing ? (int) $editing['id'] : 0; ?>">
          <div>
            <label for="name">Name / location *</label>
            <input id="name" name="name" type="text" required value="<?php echo hub_h((string) ($editing['name'] ?? '')); ?>" placeholder="Example: import.force_unchanged_feeds">
          </div>
          <div>
            <label for="heading">Popup heading</label>
            <input id="heading" name="heading" type="text" value="<?php echo hub_h((string) ($editing['heading'] ?? '')); ?>">
          </div>
          <div>
            <label for="context">Bootstrap alert style</label>
            <select id="context" name="context">
              <?php foreach (['info', 'warning', 'danger', 'success', 'primary', 'secondary', 'light', 'dark'] as $context): ?>
                <option value="<?php echo hub_h($context); ?>" <?php echo (($editing['context'] ?? 'info') === $context) ? 'selected' : ''; ?>>
                  <?php echo hub_h(ucfirst($context)); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="message_html">Message HTML *</label>
            <textarea id="message_html" name="message_html" rows="9" required><?php echo hub_h((string) ($editing['message_html'] ?? '')); ?></textarea>
          </div>
          <div class="links" style="justify-content:flex-start; align-items:center;">
            <label class="muted" style="display:flex; align-items:center; gap:8px;">
              <input type="checkbox" name="show_on_web" value="1" style="width:auto;" <?php echo ((int) ($editing['show_on_web'] ?? 1) === 1) ? 'checked' : ''; ?>> Show on web
            </label>
            <label class="muted" style="display:flex; align-items:center; gap:8px;">
              <input type="checkbox" name="archived" value="1" style="width:auto;" <?php echo !empty($editing['archived']) ? 'checked' : ''; ?>> Archived
            </label>
          </div>
          <div class="links" style="justify-content:flex-start;">
            <button type="submit"><?php echo $editing ? 'Save help message' : 'Add help message'; ?></button>
            <?php if ($editing): ?>
              <a href="/admin/help-messages.php">Add new</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <div class="card">
        <p class="brand">List</p>
        <h2>Existing help messages</h2>
        <?php if (empty($messages)): ?>
          <div class="alert info">No help messages found.</div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Heading</th>
                <th>Style</th>
                <th>Icon</th>
                <th>Visible</th>
                <th>Archived</th>
                <th>Modified</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($messages as $msg): ?>
                <tr>
                  <td><?php echo (int) $msg['id']; ?></td>
                  <td><?php echo hub_h((string) $msg['name']); ?></td>
                  <td><?php echo hub_h((string) $msg['heading']); ?></td>
                  <td><?php echo hub_h((string) ($msg['context'] ?? 'info')); ?></td>
                  <td><i class="<?php echo hub_h(hub_help_icon_class((string) ($msg['context'] ?? 'info'), (string) ($msg['icon'] ?? ''))); ?>" aria-hidden="true"></i></td>
                  <td><?php echo !empty($msg['show_on_web']) ? 'Yes' : 'No'; ?></td>
                  <td><?php echo !empty($msg['archived']) ? 'Yes' : 'No'; ?></td>
                  <td class="muted"><?php echo hub_h((string) $msg['modified']); ?></td>
                  <td><a href="/admin/help-messages.php?id=<?php echo (int) $msg['id']; ?>">Edit</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
