<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/dashboard_buttons.php';
hub_require_super_admin();

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();

function hub_admin_dashboard_button_bool(string $key): int {
  return isset($_POST[$key]) ? 1 : 0;
}

if (
  ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
  && isset($_POST['action'])
  && in_array($_POST['action'], ['add_dashboard_button', 'update_dashboard_button', 'archive_dashboard_button'], true)
) {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_dashboard_button')) {
    $error = 'Dashboard button settings are not available.';
  } elseif (($_POST['action'] ?? '') === 'archive_dashboard_button') {
    $buttonId = (int) ($_POST['button_id'] ?? 0);
    if ($buttonId <= 0) {
      $error = 'Button not found.';
    } else {
      try {
        $stmt = $pdo->prepare('UPDATE hub_dashboard_button SET archived = 1, show_on_web = 0, modified = NOW() WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $buttonId]);
        hub_log_user_action([
          'user' => hub_current_user(),
          'action_key' => 'dashboard_button_archived',
          'action_title' => 'Dashboard button removed',
          'table_name' => 'hub_dashboard_button',
          'record_id' => $buttonId,
        ]);
        hub_flash('success', 'Dashboard button removed.');
        hub_redirect('/admin/dashboard-buttons.php');
      } catch (PDOException $e) {
        $error = 'Unable to remove dashboard button.';
      }
    }
  } else {
    $action = (string) $_POST['action'];
    $buttonId = (int) ($_POST['button_id'] ?? 0);
    $buttonKey = strtolower(trim((string) ($_POST['button_key'] ?? '')));
    $buttonKey = preg_replace('/[^a-z0-9_-]+/', '-', $buttonKey) ?: '';
    $title = trim((string) ($_POST['title'] ?? ''));
    $href = trim((string) ($_POST['href'] ?? ''));
    $cssClass = trim((string) ($_POST['css_class'] ?? ''));
    $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
    $countKey = trim((string) ($_POST['count_key'] ?? ''));
    $sort = (int) ($_POST['sort'] ?? 100);

    if (($action === 'update_dashboard_button' && $buttonId <= 0) || ($action === 'add_dashboard_button' && $buttonKey === '') || $title === '') {
      $error = 'Button key and title are required.';
    } else {
      $params = [
        ':title' => $title,
        ':href' => $href !== '' ? $href : 'dashboard.php',
        ':css_class' => $cssClass,
        ':image_url' => $imageUrl,
        ':count_key' => $countKey,
        ':show_customer' => hub_admin_dashboard_button_bool('show_customer'),
        ':show_prospect' => hub_admin_dashboard_button_bool('show_prospect'),
        ':show_staff' => hub_admin_dashboard_button_bool('show_staff'),
        ':show_on_web' => hub_admin_dashboard_button_bool('show_on_web'),
        ':sort' => $sort,
      ];
      try {
        if ($action === 'add_dashboard_button') {
          $stmt = $pdo->prepare(
            'INSERT INTO hub_dashboard_button
              (button_key, title, href, css_class, image_url, count_key, show_customer, show_prospect, show_staff, show_on_web, sort, archived, created, modified)
             VALUES
              (:button_key, :title, :href, :css_class, :image_url, :count_key, :show_customer, :show_prospect, :show_staff, :show_on_web, :sort, 0, NOW(), NOW())'
          );
          $params[':button_key'] = $buttonKey;
          $stmt->execute($params);
          $buttonId = (int) $pdo->lastInsertId();
          $actionKey = 'dashboard_button_added';
          $actionTitle = 'Dashboard button added';
          $flashMessage = 'Dashboard button added.';
        } else {
          $stmt = $pdo->prepare(
            'UPDATE hub_dashboard_button
             SET title = :title,
                 href = :href,
                 css_class = :css_class,
                 image_url = :image_url,
                 count_key = :count_key,
                 show_customer = :show_customer,
                 show_prospect = :show_prospect,
                 show_staff = :show_staff,
                 show_on_web = :show_on_web,
                 sort = :sort,
                 modified = NOW()
             WHERE id = :id
             LIMIT 1'
          );
          $params[':id'] = $buttonId;
          $stmt->execute($params);
          $actionKey = 'dashboard_button_updated';
          $actionTitle = 'Dashboard button updated';
          $flashMessage = 'Dashboard button updated.';
        }
      } catch (PDOException $e) {
        $error = 'Unable to save dashboard button. The button key may already be in use.';
      }
      if ($error === null) {
        hub_log_user_action([
          'user' => hub_current_user(),
          'action_key' => $actionKey,
          'action_title' => $actionTitle,
          'table_name' => 'hub_dashboard_button',
          'record_id' => $buttonId,
          'details' => ['button_key' => $buttonKey, 'title' => $title],
        ]);
        hub_flash('success', $flashMessage);
        hub_redirect('/admin/dashboard-buttons.php');
      }
    }
  }
}

$buttons = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_dashboard_button')) {
  $stmtButtons = $pdo->query(
    'SELECT *
     FROM hub_dashboard_button
     WHERE archived = 0
     ORDER BY sort ASC, title ASC'
  );
  $buttons = $stmtButtons ? ($stmtButtons->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Dashboard Buttons</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
  <?php echo hub_admin_header('super'); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Super</p>
            <h1>Dashboard Buttons</h1>
            <p class="muted">Manage dashboard shortcut titles, links, audience visibility, counts, and ordering.</p>
          </div>
          <div class="links">
            <a href="/super.php">Back to Super</a>
            <a href="/dashboard.php">Dashboard</a>
            <button type="button" data-open-modal="dashboardButtonAddModal">Add Button</button>
          </div>
        </div>
        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endif; ?>
      </div>

      <div class="card">
        <p class="brand">Visibility</p>
        <h2>Shortcut Rules</h2>
        <?php if (empty($buttons)): ?>
          <div class="alert info">No dashboard buttons found. Run the schema installer to seed the defaults.</div>
        <?php else: ?>
          <div class="import-data-table-wrap">
            <table class="table import-data-table">
              <thead>
                <tr>
                  <th>Sort</th>
                  <th>Title</th>
                  <th>Link</th>
                  <th>Count</th>
                  <th>Customer</th>
                  <th>Prospect</th>
                  <th>Staff</th>
                  <th>Live</th>
                  <th class="table-actions-heading">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($buttons as $button): ?>
                  <?php $modalId = 'dashboardButtonModal' . (int) $button['id']; ?>
                  <tr>
                    <td><?php echo (int) ($button['sort'] ?? 0); ?></td>
                    <td><?php echo hub_h((string) ($button['title'] ?? '')); ?></td>
                    <td><?php echo hub_h((string) ($button['href'] ?? '')); ?></td>
                    <td><?php echo trim((string) ($button['count_key'] ?? '')) !== '' ? hub_h((string) $button['count_key']) : '<span class="muted">-</span>'; ?></td>
                    <td><?php echo !empty($button['show_customer']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo !empty($button['show_prospect']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo !empty($button['show_staff']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo !empty($button['show_on_web']) ? 'Yes' : 'No'; ?></td>
                    <td class="table-actions">
                      <button type="button" class="icon-action" data-open-modal="<?php echo hub_h($modalId); ?>" title="Edit <?php echo hub_h((string) ($button['title'] ?? 'button')); ?>" aria-label="Edit <?php echo hub_h((string) ($button['title'] ?? 'button')); ?>"><i class="fa-solid fa-pencil" aria-hidden="true"></i></button>
                      <form method="post" action="/admin/dashboard-buttons.php" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                        <input type="hidden" name="action" value="archive_dashboard_button">
                        <input type="hidden" name="button_id" value="<?php echo (int) $button['id']; ?>">
                        <button type="submit" class="icon-action" title="Remove <?php echo hub_h((string) ($button['title'] ?? 'button')); ?>" aria-label="Remove <?php echo hub_h((string) ($button['title'] ?? 'button')); ?>"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php foreach ($buttons as $button): ?>
            <?php $modalId = 'dashboardButtonModal' . (int) $button['id']; ?>
            <div class="modal admin-entity-modal" id="<?php echo hub_h($modalId); ?>" aria-hidden="true">
              <div class="modal-content">
                <div class="modal-header">
                  <h2>Edit Dashboard Button</h2>
                  <button type="button" class="close-btn but2" data-close-modal="<?php echo hub_h($modalId); ?>" aria-label="Close">&times;</button>
                </div>
                <form method="post" action="/admin/dashboard-buttons.php">
                  <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                  <input type="hidden" name="action" value="update_dashboard_button">
                  <input type="hidden" name="button_id" value="<?php echo (int) $button['id']; ?>">
                  <div>
                    <label for="title_<?php echo (int) $button['id']; ?>">Title</label>
                    <input id="title_<?php echo (int) $button['id']; ?>" name="title" type="text" value="<?php echo hub_h((string) ($button['title'] ?? '')); ?>" required>
                  </div>
                  <div>
                    <label for="href_<?php echo (int) $button['id']; ?>">Link / URL</label>
                    <input id="href_<?php echo (int) $button['id']; ?>" name="href" type="text" value="<?php echo hub_h((string) ($button['href'] ?? '')); ?>">
                  </div>
                  <div>
                    <label for="css_class_<?php echo (int) $button['id']; ?>">CSS Class</label>
                    <input id="css_class_<?php echo (int) $button['id']; ?>" name="css_class" type="text" value="<?php echo hub_h((string) ($button['css_class'] ?? '')); ?>">
                  </div>
                  <div>
                    <label for="image_url_<?php echo (int) $button['id']; ?>">Image URL</label>
                    <input id="image_url_<?php echo (int) $button['id']; ?>" name="image_url" type="text" value="<?php echo hub_h((string) ($button['image_url'] ?? '')); ?>" placeholder="Optional">
                    <p class="muted">Leave blank to use the default image from the CSS class.</p>
                  </div>
                  <div>
                    <label for="count_key_<?php echo (int) $button['id']; ?>">Count Key</label>
                    <select id="count_key_<?php echo (int) $button['id']; ?>" name="count_key">
                      <?php foreach (['' => 'None', 'shipping' => 'Shipping', 'inventory' => 'Inventory'] as $countKey => $countLabel): ?>
                        <option value="<?php echo hub_h($countKey); ?>" <?php echo ((string) ($button['count_key'] ?? '') === $countKey) ? 'selected' : ''; ?>><?php echo hub_h($countLabel); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label for="sort_<?php echo (int) $button['id']; ?>">Sort</label>
                    <input id="sort_<?php echo (int) $button['id']; ?>" name="sort" type="number" value="<?php echo (int) ($button['sort'] ?? 100); ?>">
                  </div>
                  <div class="modal-checkbox-row dashboard-button-visibility">
                    <label><input type="checkbox" name="show_customer" value="1" <?php echo !empty($button['show_customer']) ? 'checked' : ''; ?>> <span>Show for Customers</span></label>
                    <label><input type="checkbox" name="show_prospect" value="1" <?php echo !empty($button['show_prospect']) ? 'checked' : ''; ?>> <span>Show for Prospects</span></label>
                    <label><input type="checkbox" name="show_staff" value="1" <?php echo !empty($button['show_staff']) ? 'checked' : ''; ?>> <span>Show for RxSource Staff</span></label>
                    <label><input type="checkbox" name="show_on_web" value="1" <?php echo !empty($button['show_on_web']) ? 'checked' : ''; ?>> <span>Live</span></label>
                  </div>
                  <div class="links admin-page-actions"><button type="submit">Save Button</button></div>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="modal admin-entity-modal" id="dashboardButtonAddModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Add Dashboard Button</h2>
        <button type="button" class="close-btn but2" data-close-modal="dashboardButtonAddModal" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="/admin/dashboard-buttons.php">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="add_dashboard_button">
        <div>
          <label for="add_button_key">Button Key</label>
          <input id="add_button_key" name="button_key" type="text" placeholder="marketing-extra" required>
        </div>
        <div>
          <label for="add_title">Title</label>
          <input id="add_title" name="title" type="text" required>
        </div>
        <div>
          <label for="add_href">Link / URL</label>
          <input id="add_href" name="href" type="text" value="dashboard.php">
        </div>
        <div>
          <label for="add_css_class">CSS Class</label>
          <input id="add_css_class" name="css_class" type="text" value="portal-dashboard-tile-marketing">
        </div>
        <div>
          <label for="add_image_url">Image URL</label>
          <input id="add_image_url" name="image_url" type="text" placeholder="Optional">
          <p class="muted">Leave blank to use the default image from the CSS class.</p>
        </div>
        <div>
          <label for="add_count_key">Count Key</label>
          <select id="add_count_key" name="count_key">
            <option value="">None</option>
            <option value="shipping">Shipping</option>
            <option value="inventory">Inventory</option>
          </select>
        </div>
        <div>
          <label for="add_sort">Sort</label>
          <input id="add_sort" name="sort" type="number" value="100">
        </div>
        <div class="modal-checkbox-row dashboard-button-visibility">
          <label><input type="checkbox" name="show_customer" value="1"> <span>Show for Customers</span></label>
          <label><input type="checkbox" name="show_prospect" value="1"> <span>Show for Prospects</span></label>
          <label><input type="checkbox" name="show_staff" value="1"> <span>Show for RxSource Staff</span></label>
          <label><input type="checkbox" name="show_on_web" value="1" checked> <span>Live</span></label>
        </div>
        <div class="links admin-page-actions"><button type="submit">Add Button</button></div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      document.addEventListener('click', function (event) {
        var open = event.target.closest('[data-open-modal]');
        if (open) {
          var modal = document.getElementById(open.getAttribute('data-open-modal'));
          if (modal) {
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
          }
          return;
        }
        var close = event.target.closest('[data-close-modal]');
        if (close) {
          var closeModal = document.getElementById(close.getAttribute('data-close-modal'));
          if (closeModal) {
            closeModal.classList.remove('open');
            closeModal.setAttribute('aria-hidden', 'true');
          }
          return;
        }
        if (event.target.classList && event.target.classList.contains('modal')) {
          event.target.classList.remove('open');
          event.target.setAttribute('aria-hidden', 'true');
        }
      });
      document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.modal.open').forEach(function (modal) {
          modal.classList.remove('open');
          modal.setAttribute('aria-hidden', 'true');
        });
      });
    })();
  </script>
</body>
</html>
