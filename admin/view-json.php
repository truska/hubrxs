<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_admin();

$sourceDir = realpath(__DIR__ . '/../sourcedata');
$files = [];
if ($sourceDir && is_dir($sourceDir)) {
  foreach (glob($sourceDir . '/*.json') ?: [] as $path) {
    if (!is_file($path)) {
      continue;
    }
    $files[] = [
      'name' => basename($path),
      'path' => $path,
      'mtime' => filemtime($path) ?: 0,
      'size' => filesize($path) ?: 0,
    ];
  }
}
usort($files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

$selectedName = basename((string) ($_GET['file'] ?? ''));
if ($selectedName === '' && $files) {
  $selectedName = $files[0]['name'];
}

$selected = null;
foreach ($files as $file) {
  if ($file['name'] === $selectedName) {
    $selected = $file;
    break;
  }
}

$content = '';
$error = null;
if ($selected) {
  $raw = file_get_contents($selected['path']);
  if ($raw === false) {
    $error = 'Unable to read selected JSON file.';
  } else {
    $decoded = json_decode($raw, true);
    $content = is_array($decoded)
      ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
      : $raw;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | View JSON</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page view-json-page">
  <?php echo hub_admin_header('admin'); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Imports</p>
            <h1>View JSON</h1>
            <p class="muted">Inspect saved source JSON files from recent imports.</p>
          </div>
          <div class="links">
            <a href="/admin.php">Back to admin</a>
            <?php if ($selected): ?>
              <a href="/sourcedata/<?php echo rawurlencode($selected['name']); ?>">Open raw file</a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endif; ?>

        <?php if (!$files): ?>
          <div class="alert info">No saved JSON files found in sourcedata.</div>
        <?php else: ?>
          <form method="get" action="/admin/view-json.php" class="import-data-controls admin-table-controls">
            <div>
              <label for="file">Saved JSON file</label>
              <select id="file" name="file" onchange="this.form.submit()">
                <?php foreach ($files as $file): ?>
                  <option value="<?php echo hub_h($file['name']); ?>" <?php echo $selectedName === $file['name'] ? 'selected' : ''; ?>>
                    <?php echo hub_h($file['name'] . ' - ' . date('Y-m-d H:i:s', $file['mtime']) . ' - ' . number_format($file['size']) . ' bytes'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>

          <textarea class="json-viewer" readonly><?php echo hub_h($content); ?></textarea>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>