<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_super_admin();

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();

function hub_admin_key_people_bool(string $key): int {
  return isset($_POST[$key]) ? 1 : 0;
}

function hub_admin_key_people_redirect(int $customerId): void {
  hub_redirect('/admin/key-people.php' . ($customerId > 0 ? '?customer_id=' . $customerId : ''));
}

function hub_admin_key_people_customer_exists(int $customerId): bool {
  global $pdo, $DB_OK;
  if ($customerId <= 0 || !$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_customer')) {
    return false;
  }
  $stmt = $pdo->prepare('SELECT id FROM hub_customer WHERE id = :id AND archived = 0 LIMIT 1');
  $stmt->execute([':id' => $customerId]);
  return (bool) $stmt->fetchColumn();
}

function hub_admin_key_people_role_exists(int $roleId): bool {
  global $pdo, $DB_OK;
  if ($roleId <= 0 || !$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_key_info_role')) {
    return false;
  }
  $stmt = $pdo->prepare('SELECT id FROM hub_key_info_role WHERE id = :id AND archived = 0 LIMIT 1');
  $stmt->execute([':id' => $roleId]);
  return (bool) $stmt->fetchColumn();
}

function hub_admin_key_people_user_is_staff(int $userId): bool {
  global $pdo, $DB_OK;
  if ($userId <= 0 || !$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user')) {
    return false;
  }
  $stmt = $pdo->prepare('SELECT role FROM hub_user WHERE id = :id AND archived = 0 LIMIT 1');
  $stmt->execute([':id' => $userId]);
  $role = $stmt->fetchColumn();
  return is_string($role) && hub_role_rank($role) >= hub_role_rank('admin');
}

function hub_admin_key_people_assignment_exists(int $assignmentId, int $customerId): bool {
  global $pdo, $DB_OK;
  if ($assignmentId <= 0 || $customerId <= 0 || !$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_customer_key_info_user')) {
    return false;
  }
  $stmt = $pdo->prepare('SELECT id FROM hub_customer_key_info_user WHERE id = :id AND customer_id = :customer_id LIMIT 1');
  $stmt->execute([
    ':id' => $assignmentId,
    ':customer_id' => $customerId,
  ]);
  return (bool) $stmt->fetchColumn();
}

if (
  ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
  && isset($_POST['action'])
  && in_array($_POST['action'], ['add_key_person', 'update_key_person', 'archive_key_person'], true)
) {
  $customerId = (int) ($_POST['customer_id'] ?? 0);
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_customer_key_info_user')) {
    $error = 'Key people settings are not available.';
  } elseif (!hub_admin_key_people_customer_exists($customerId)) {
    $error = 'Please select a customer.';
  } elseif (($_POST['action'] ?? '') === 'archive_key_person') {
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
    if (!hub_admin_key_people_assignment_exists($assignmentId, $customerId)) {
      $error = 'Key person assignment not found.';
    } else {
      $stmt = $pdo->prepare('UPDATE hub_customer_key_info_user SET archived = 1, show_on_web = 0, modified = NOW() WHERE id = :id AND customer_id = :customer_id LIMIT 1');
      $stmt->execute([
        ':id' => $assignmentId,
        ':customer_id' => $customerId,
      ]);
      hub_log_user_action([
        'user' => hub_current_user(),
        'action_key' => 'customer_key_person_archived',
        'action_title' => 'Customer key person removed',
        'table_name' => 'hub_customer_key_info_user',
        'record_id' => $assignmentId,
        'details' => ['customer_id' => $customerId],
      ]);
      hub_flash('success', 'Key person removed.');
      hub_admin_key_people_redirect($customerId);
    }
  } else {
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $userId = (int) ($_POST['user_id'] ?? 0);
    $sort = (int) ($_POST['sort'] ?? 100);
    $showOnWeb = hub_admin_key_people_bool('show_on_web');
    $action = (string) $_POST['action'];

    if ($action === 'update_key_person' && !hub_admin_key_people_assignment_exists($assignmentId, $customerId)) {
      $error = 'Key person assignment not found.';
    } elseif (!hub_admin_key_people_role_exists($roleId)) {
      $error = 'Please select a valid key role.';
    } elseif (!hub_admin_key_people_user_is_staff($userId)) {
      $error = 'Please select an Admin, Super Admin, or Developer user.';
    } else {
      try {
        if ($action === 'add_key_person') {
          $stmt = $pdo->prepare(
            'INSERT INTO hub_customer_key_info_user
              (role_id, customer_id, user_id, sort, show_on_web, archived, created, modified)
             VALUES
              (:role_id, :customer_id, :user_id, :sort, :show_on_web, 0, NOW(), NOW())'
          );
          $stmt->execute([
            ':role_id' => $roleId,
            ':customer_id' => $customerId,
            ':user_id' => $userId,
            ':sort' => $sort,
            ':show_on_web' => $showOnWeb,
          ]);
          $assignmentId = (int) $pdo->lastInsertId();
          $actionKey = 'customer_key_person_added';
          $actionTitle = 'Customer key person added';
          $flashMessage = 'Key person added.';
        } else {
          $stmt = $pdo->prepare(
            'UPDATE hub_customer_key_info_user
             SET role_id = :role_id,
                 user_id = :user_id,
                 sort = :sort,
                 show_on_web = :show_on_web,
                 modified = NOW()
             WHERE id = :id
               AND customer_id = :customer_id
             LIMIT 1'
          );
          $stmt->execute([
            ':role_id' => $roleId,
            ':user_id' => $userId,
            ':sort' => $sort,
            ':show_on_web' => $showOnWeb,
            ':id' => $assignmentId,
            ':customer_id' => $customerId,
          ]);
          $actionKey = 'customer_key_person_updated';
          $actionTitle = 'Customer key person updated';
          $flashMessage = 'Key person updated.';
        }
        hub_log_user_action([
          'user' => hub_current_user(),
          'action_key' => $actionKey,
          'action_title' => $actionTitle,
          'table_name' => 'hub_customer_key_info_user',
          'record_id' => $assignmentId,
          'details' => ['customer_id' => $customerId, 'role_id' => $roleId, 'user_id' => $userId],
        ]);
        hub_flash('success', $flashMessage);
        hub_admin_key_people_redirect($customerId);
      } catch (PDOException $e) {
        $error = 'Unable to save key person. Check the sort order is not already used for that customer role.';
      }
    }
  }
}

$selectedCustomerId = (int) ($_GET['customer_id'] ?? ($_POST['customer_id'] ?? 0));
$customers = [];
$roles = [];
$staffUsers = [];
$assignments = [];

if ($DB_OK && ($pdo instanceof PDO)) {
  if (hub_table_exists('hub_customer')) {
    $stmtCustomers = $pdo->query('SELECT id, name, code FROM hub_customer WHERE archived = 0 ORDER BY name ASC');
    $customers = $stmtCustomers ? ($stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  }
  if (hub_table_exists('hub_key_info_role')) {
    $stmtRoles = $pdo->query('SELECT id, role_key, label, section_label, default_sort FROM hub_key_info_role WHERE archived = 0 ORDER BY default_sort ASC, label ASC');
    $roles = $stmtRoles ? ($stmtRoles->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  }
  if (hub_table_exists('hub_user')) {
    $stmtUsers = $pdo->query('SELECT id, display_name, email, job_title, role FROM hub_user WHERE archived = 0 ORDER BY display_name ASC, email ASC');
    foreach (($stmtUsers ? ($stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) : []) as $staffUser) {
      if (hub_role_rank((string) ($staffUser['role'] ?? 'user')) >= hub_role_rank('admin')) {
        $staffUsers[] = $staffUser;
      }
    }
  }
  if ($selectedCustomerId > 0 && hub_table_exists('hub_customer_key_info_user')) {
    $stmtAssignments = $pdo->prepare(
      'SELECT
         a.*,
         r.label AS role_label,
         r.section_label,
         u.display_name,
         u.email,
         u.job_title,
         u.role AS user_role
       FROM hub_customer_key_info_user a
       INNER JOIN hub_key_info_role r ON r.id = a.role_id
       INNER JOIN hub_user u ON u.id = a.user_id
       WHERE a.customer_id = :customer_id
         AND a.archived = 0
       ORDER BY a.sort ASC, r.default_sort ASC, a.id ASC'
    );
    $stmtAssignments->execute([':customer_id' => $selectedCustomerId]);
    foreach ($stmtAssignments->fetchAll(PDO::FETCH_ASSOC) ?: [] as $assignment) {
      if (hub_role_rank((string) ($assignment['user_role'] ?? 'user')) >= hub_role_rank('admin')) {
        $assignments[] = $assignment;
      }
    }
  }
}

function hub_admin_key_people_user_label(array $user): string {
  $name = trim((string) ($user['display_name'] ?? ''));
  $email = trim((string) ($user['email'] ?? ''));
  $title = trim((string) ($user['job_title'] ?? ''));
  $label = $name !== '' ? $name : $email;
  if ($title !== '') {
    $label .= ' - ' . $title;
  }
  return $label;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Key People</title>
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
            <h1>Key People</h1>
            <p class="muted">Assign RxSource staff contacts to each customer's dashboard.</p>
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
      </div>

      <div class="card">
        <form method="get" action="/admin/key-people.php" class="admin-inline-form">
          <label for="customer_id">Customer</label>
          <select id="customer_id" name="customer_id" onchange="this.form.submit()">
            <option value="">Select a customer</option>
            <?php foreach ($customers as $customer): ?>
              <option value="<?php echo (int) $customer['id']; ?>" <?php echo (int) $customer['id'] === $selectedCustomerId ? 'selected' : ''; ?>>
                <?php echo hub_h((string) ($customer['name'] ?? '')); ?><?php echo trim((string) ($customer['code'] ?? '')) !== '' ? ' (' . hub_h((string) $customer['code']) . ')' : ''; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit">Load</button>
        </form>
      </div>

      <?php if ($selectedCustomerId > 0): ?>
        <div class="card">
          <div class="flex">
            <div>
              <p class="brand">Dashboard</p>
              <h2>Assigned People</h2>
              <p class="muted">The dashboard uses this sort order, split evenly into two columns.</p>
            </div>
          </div>
          <?php if (empty($assignments)): ?>
            <div class="alert info">No key people are assigned to this customer.</div>
          <?php else: ?>
            <div class="import-data-table-wrap">
              <table class="table import-data-table">
                <thead>
                  <tr>
                    <th>Sort</th>
                    <th>Role</th>
                    <th>Person</th>
                    <th>Live</th>
                    <th class="table-actions-heading">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($assignments as $assignment): ?>
                    <tr>
                      <td><?php echo (int) ($assignment['sort'] ?? 0); ?></td>
                      <td><?php echo hub_h((string) ($assignment['role_label'] ?? '')); ?></td>
                      <td>
                        <?php echo hub_h(hub_admin_key_people_user_label($assignment)); ?>
                        <?php if (!empty($assignment['email'])): ?>
                          <small class="muted"><?php echo hub_h((string) $assignment['email']); ?></small>
                        <?php endif; ?>
                      </td>
                      <td><?php echo !empty($assignment['show_on_web']) ? 'Yes' : 'No'; ?></td>
                      <td class="table-actions">
                        <button type="button" class="icon-action" data-open-modal="keyPersonModal<?php echo (int) $assignment['id']; ?>" title="Edit key person" aria-label="Edit key person"><i class="fa-solid fa-pencil" aria-hidden="true"></i></button>
                        <form method="post" action="/admin/key-people.php" style="display:inline;">
                          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                          <input type="hidden" name="action" value="archive_key_person">
                          <input type="hidden" name="customer_id" value="<?php echo $selectedCustomerId; ?>">
                          <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment['id']; ?>">
                          <button type="submit" class="icon-action" title="Remove key person" aria-label="Remove key person"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="card">
          <p class="brand">Add New</p>
          <h2>Add Key Person</h2>
          <form method="post" action="/admin/key-people.php">
            <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
            <input type="hidden" name="action" value="add_key_person">
            <input type="hidden" name="customer_id" value="<?php echo $selectedCustomerId; ?>">
            <div>
              <label for="role_id_add">Role</label>
              <select id="role_id_add" name="role_id" required>
                <option value="">Select role</option>
                <?php foreach ($roles as $role): ?>
                  <option value="<?php echo (int) $role['id']; ?>"><?php echo hub_h((string) ($role['label'] ?? '')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="user_id_add">Person</label>
              <select id="user_id_add" name="user_id" required>
                <option value="">Select person</option>
                <?php foreach ($staffUsers as $staffUser): ?>
                  <option value="<?php echo (int) $staffUser['id']; ?>"><?php echo hub_h(hub_admin_key_people_user_label($staffUser)); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="sort_add">Sort</label>
              <input id="sort_add" name="sort" type="number" value="100">
            </div>
            <div class="modal-checkbox-row dashboard-button-visibility">
              <label><input type="checkbox" name="show_on_web" value="1" checked> <span>Live</span></label>
            </div>
            <div class="links admin-page-actions"><button type="submit">Add Key Person</button></div>
          </form>
        </div>

        <?php foreach ($assignments as $assignment): ?>
          <div class="modal admin-entity-modal" id="keyPersonModal<?php echo (int) $assignment['id']; ?>" aria-hidden="true">
            <div class="modal-content">
              <div class="modal-header">
                <h2>Edit Key Person</h2>
                <button type="button" class="close-btn but2" data-close-modal="keyPersonModal<?php echo (int) $assignment['id']; ?>" aria-label="Close">&times;</button>
              </div>
              <form method="post" action="/admin/key-people.php">
                <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                <input type="hidden" name="action" value="update_key_person">
                <input type="hidden" name="customer_id" value="<?php echo $selectedCustomerId; ?>">
                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment['id']; ?>">
                <div>
                  <label for="role_id_<?php echo (int) $assignment['id']; ?>">Role</label>
                  <select id="role_id_<?php echo (int) $assignment['id']; ?>" name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                      <option value="<?php echo (int) $role['id']; ?>" <?php echo (int) $role['id'] === (int) $assignment['role_id'] ? 'selected' : ''; ?>><?php echo hub_h((string) ($role['label'] ?? '')); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="user_id_<?php echo (int) $assignment['id']; ?>">Person</label>
                  <select id="user_id_<?php echo (int) $assignment['id']; ?>" name="user_id" required>
                    <?php foreach ($staffUsers as $staffUser): ?>
                      <option value="<?php echo (int) $staffUser['id']; ?>" <?php echo (int) $staffUser['id'] === (int) $assignment['user_id'] ? 'selected' : ''; ?>><?php echo hub_h(hub_admin_key_people_user_label($staffUser)); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="sort_<?php echo (int) $assignment['id']; ?>">Sort</label>
                  <input id="sort_<?php echo (int) $assignment['id']; ?>" name="sort" type="number" value="<?php echo (int) ($assignment['sort'] ?? 100); ?>">
                </div>
                <div class="modal-checkbox-row dashboard-button-visibility">
                  <label><input type="checkbox" name="show_on_web" value="1" <?php echo !empty($assignment['show_on_web']) ? 'checked' : ''; ?>> <span>Live</span></label>
                </div>
                <div class="links admin-page-actions"><button type="submit">Save Key Person</button></div>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="card">
          <div class="alert info">Select a customer to manage dashboard key people.</div>
        </div>
      <?php endif; ?>
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
    })();
  </script>
</body>
</html>
