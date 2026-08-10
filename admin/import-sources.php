<?php
require_once __DIR__ . '/../includes/app/imports/remote_sources.php';
require_once __DIR__ . '/../includes/app/help.php';
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_admin();

$sources = hub_import_list_sources(true);
$recent = hub_import_recent_fetches(null, 30);
$messages = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Import Sources</title>
  <?php echo hub_fontawesome_css(); ?>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
  <?php echo hub_admin_header('admin'); ?>
  <div class="stack">
    <div class="stack">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Admin</p>
            <h1>Import sources</h1>
            <p class="muted">Review configured JSON feeds and trigger updates.</p>
          </div>
          <div class="links">
            <a href="/admin.php">Back to admin</a>
            <a href="/admin/import-data.php">View data</a>
            <a href="/dashboard.php">Dashboard</a>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>

        <?php if (empty($sources)): ?>
          <div class="alert info">No import sources are configured yet.</div>
        <?php else: ?>
          <div class="import-working" id="import-working" role="status" aria-live="assertive">
            <div class="import-working-icon">
              <i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i>
            </div>
            <div>
              <strong>Import running</strong>
              <p>Please wait while the feed is downloaded and processed. Do not close this page.</p>
            </div>
          </div>

          <form method="post" action="/tools/run_remote_imports.php" class="import-run-form" style="margin: 14px 0;">
            <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
            <div class="links" style="justify-content:flex-start;">
              <span class="help-inline">
                <button type="submit" data-running-text="Running imports...">Run all active imports</button>
                <?php echo hub_help_button('import.run_all_active_imports', 'Explain run all active imports'); ?>
              </span>
              <label class="muted" style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" name="force" value="1" style="width:auto;"> Force unchanged feeds
                <?php echo hub_help_button('import.force_unchanged_feeds', 'Explain force unchanged feeds'); ?>
              </label>
            </div>
          </form>

          <table class="table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Handler</th>
                <th>Auth</th>
                <th>Visible</th>
                <th>Archived</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sources as $source): ?>
                <tr>
                  <td>
                    <strong><?php echo hub_h((string) $source['name']); ?></strong>
                    <div class="muted"><?php echo hub_h((string) $source['import_key']); ?></div>
                  </td>
                  <td><?php echo hub_h((string) $source['handler']); ?></td>
                  <td>
                    <?php echo hub_h((string) $source['auth_type']); ?>
                    <?php if (!empty($source['auth_username'])): ?>
                      <div class="muted"><?php echo hub_h((string) $source['auth_username']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?php echo !empty($source['show_on_web']) ? 'Yes' : 'No'; ?></td>
                  <td><?php echo !empty($source['archived']) ? 'Yes' : 'No'; ?></td>
                  <td>
                    <?php if (empty($source['archived'])): ?>
                      <form method="post" action="/tools/run_remote_imports.php" class="import-run-form" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                        <input type="hidden" name="source_id" value="<?php echo (int) $source['id']; ?>">
                        <span class="help-inline">
                          <button class="but1" type="submit" data-running-text="Running...">Run</button>
                          <?php echo hub_help_button('import.run_single_source', 'Explain run import source'); ?>
                        </span>
                      </form>
                    <?php else: ?>
                      <span class="muted">Archived</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="card">
        <p class="brand">History</p>
        <h2>Recent import runs</h2>
        <?php if (empty($recent)): ?>
          <div class="alert info">No import runs recorded yet.</div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Source</th>
                <th>Status</th>
                <th>Rows</th>
                <th>HTTP</th>
                <th>Started</th>
                <th>Finished</th>
                <th>Result</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent as $run): ?>
                <tr>
                  <td>
                    <?php echo hub_h((string) $run['source_name']); ?>
                    <div class="muted">#<?php echo (int) $run['id']; ?> <?php echo hub_h((string) $run['import_key']); ?></div>
                  </td>
                  <td>
                    <?php echo hub_h((string) $run['status']); ?>
                    <?php if (!empty($run['skipped'])): ?>
                      <div class="muted">unchanged</div>
                    <?php endif; ?>
                  </td>
                  <td><?php echo (int) $run['processed_count']; ?> / <?php echo (int) $run['row_count']; ?></td>
                  <td><?php echo $run['http_status'] ? (int) $run['http_status'] : ''; ?></td>
                  <td class="muted"><?php echo hub_h((string) $run['started_at']); ?></td>
                  <td class="muted"><?php echo hub_h((string) $run['finished_at']); ?></td>
                  <td class="muted import-result-cell">
                    <?php if (!empty($run['error_text'])): ?>
                      <?php echo hub_h((string) $run['error_text']); ?>
                    <?php else: ?>
                      <?php echo hub_h(substr((string) $run['sha256'], 0, 12)); ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php echo hub_help_modal_markup(); ?>
  <script>
    (function () {
      var working = document.getElementById('import-working');
      var forms = document.querySelectorAll('.import-run-form');
      function resetImportState() {
        if (working) {
          working.classList.remove('visible');
        }
        document.body.classList.remove('import-is-running');
        document.querySelectorAll('[data-import-disabled="1"]').forEach(function (control) {
          control.disabled = false;
          control.removeAttribute('data-import-disabled');
        });
        document.querySelectorAll('[data-original-text]').forEach(function (button) {
          button.textContent = button.getAttribute('data-original-text');
          button.removeAttribute('data-original-text');
        });
        document.querySelectorAll('.import-run-form[data-submitting="1"]').forEach(function (form) {
          form.removeAttribute('data-submitting');
        });
      }
      forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
          if (form.getAttribute('data-submitting') === '1') {
            event.preventDefault();
            return;
          }
          form.setAttribute('data-submitting', '1');
          if (working) {
            working.classList.add('visible');
          }
          document.body.classList.add('import-is-running');
          document.querySelectorAll('button, input, select, textarea').forEach(function (control) {
            if (control.classList.contains('help-trigger')) return;
            if (control.form === form) return;
            control.setAttribute('data-import-disabled', '1');
            control.disabled = true;
          });
          document.querySelectorAll('.import-run-form button[type="submit"]').forEach(function (button) {
            var runningText = button.getAttribute('data-running-text');
            if (runningText) {
              button.setAttribute('data-original-text', button.textContent);
              button.textContent = runningText;
            }
          });
        });
      });
      window.addEventListener('pageshow', resetImportState);
    })();
  </script>
</body>
</html>
