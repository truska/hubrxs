<?php
require_once __DIR__ . '/includes/app/admin_layout.php';
require_once __DIR__ . '/includes/app/admin_tools.php';
hub_require_admin();

global $pdo, $DB_OK;

$currentUser = hub_current_user();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$assignedCustomerId = isset($currentUser['customer_id']) ? (int) $currentUser['customer_id'] : null;
$overrideCustomerId = hub_current_customer_override();
$effectiveCustomerId = hub_effective_customer_id($currentUser);
$showAllActions = (($_GET['show_actions'] ?? '') === 'all');
$actionError = null;
$customerSwitchError = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reset_customer_override') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $customerSwitchError = 'Session expired. Please try again.';
  } else {
    hub_set_customer_override(null);
    hub_log_user_action([
      'user' => $currentUser,
      'action_key' => 'customer_view_reset',
      'action_title' => 'Customer view reset',
      'table_name' => 'hub_customer',
      'details' => ['previous_customer_id' => $overrideCustomerId, 'assigned_customer_id' => $assignedCustomerId],
    ]);
    hub_flash('success', 'Customer view reset to your assigned customer.');
    hub_redirect('/admin.php');
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'change_customer_override') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $customerSwitchError = 'Session expired. Please try again.';
  } else {
    $selected = trim((string) ($_POST['customer_id'] ?? ''));
    if ($selected === '') {
      hub_set_customer_override(null);
      hub_log_user_action([
        'user' => $currentUser,
        'action_key' => 'customer_view_reset',
        'action_title' => 'Customer view reset',
        'table_name' => 'hub_customer',
        'details' => ['previous_customer_id' => $overrideCustomerId, 'assigned_customer_id' => $assignedCustomerId],
      ]);
      hub_flash('success', 'Customer view reset to your assigned customer.');
      hub_redirect('/admin.php');
    }

    $selectedId = (int) $selected;
    $exists = false;
    if ($selectedId > 0 && $DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
      $stmtCheck = $pdo->prepare('SELECT id FROM hub_customer WHERE id = :id AND archived = 0 LIMIT 1');
      $stmtCheck->execute([':id' => $selectedId]);
      $exists = (bool) $stmtCheck->fetchColumn();
    }

    if ($exists) {
      hub_set_customer_override($selectedId);
      hub_log_user_action([
        'user' => $currentUser,
        'action_key' => 'customer_view_changed',
        'action_title' => 'Customer view changed',
        'table_name' => 'hub_customer',
        'record_id' => $selectedId,
        'details' => ['previous_customer_id' => $overrideCustomerId, 'selected_customer_id' => $selectedId],
      ]);
      hub_flash('success', 'Temporary customer view changed.');
      hub_redirect('/admin.php');
    }

    $customerSwitchError = 'Customer not found.';
  }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_admin_action') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $actionError = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_admin_action')) {
    $actionError = 'Admin actions are not available.';
  } else {
    $actionId = (int) ($_POST['action_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if ($actionId > 0 && in_array($status, ['complete', 'dismissed'], true)) {
      $scopeSql = $showAllActions ? '' : ' AND (assigned_user_id IS NULL OR assigned_user_id = :user_id)';
      $stmt = $pdo->prepare(
        'UPDATE hub_admin_action
         SET status = :status, modified = NOW()
         WHERE id = :id
           AND status = :open_status' . $scopeSql
      );
      $params = [
        ':status' => $status,
        ':id' => $actionId,
        ':open_status' => 'open',
      ];
      if (!$showAllActions) {
        $params[':user_id'] = $currentUserId;
      }
      $stmt->execute($params);
      hub_log_user_action([
        'user' => $currentUser,
        'action_key' => 'admin_action_' . $status,
        'action_title' => $status === 'complete' ? 'Admin action completed' : 'Admin action dismissed',
        'table_name' => 'hub_admin_action',
        'record_id' => $actionId,
        'sql_text' => 'UPDATE hub_admin_action SET status = :status, modified = NOW() WHERE id = :id AND status = :open_status',
        'details' => ['status' => $status, 'show_all_actions' => $showAllActions],
      ]);
      hub_flash('success', $status === 'complete' ? 'Action marked complete.' : 'Action dismissed.');
      hub_redirect('/admin.php' . ($showAllActions ? '?show_actions=all' : ''));
    }
  }
}

$openActions = 0;
$adminActions = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_admin_action')) {
  $openActions = (int) $pdo->query("SELECT COUNT(*) FROM hub_admin_action WHERE status = 'open'")->fetchColumn();

  if ($showAllActions) {
    $stmtActions = $pdo->query(
      'SELECT a.*, u.email AS assigned_email
       FROM hub_admin_action a
       LEFT JOIN hub_user u ON u.id = a.assigned_user_id
       WHERE a.status = "open"
       ORDER BY FIELD(a.priority, "high", "normal", "low"), a.created ASC, a.id ASC
       LIMIT 100'
    );
  } else {
    $stmtActions = $pdo->prepare(
      'SELECT a.*, u.email AS assigned_email
       FROM hub_admin_action a
       LEFT JOIN hub_user u ON u.id = a.assigned_user_id
       WHERE a.status = :status
         AND (a.assigned_user_id IS NULL OR a.assigned_user_id = :user_id)
       ORDER BY FIELD(a.priority, "high", "normal", "low"), a.created ASC, a.id ASC
       LIMIT 100'
    );
    $stmtActions->execute([
      ':status' => 'open',
      ':user_id' => $currentUserId,
    ]);
  }
  $adminActions = $stmtActions ? ($stmtActions->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

$adminCustomerOptions = [];
$assignedCustomerLabel = 'No customer assigned';
$effectiveCustomerLabel = 'No customer selected';
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
  $stmtCustomers = $pdo->query('SELECT id, name, code FROM hub_customer WHERE archived = 0 ORDER BY name ASC, code ASC LIMIT 500');
  $adminCustomerOptions = $stmtCustomers ? ($stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

  foreach ($adminCustomerOptions as $customerOption) {
    $label = trim((string) ($customerOption['name'] ?? ''));
    $code = trim((string) ($customerOption['code'] ?? ''));
    if ($label === '') {
      $label = $code !== '' ? $code : 'Customer #' . (int) $customerOption['id'];
    }
    if ($code !== '' && $code !== $label) {
      $label .= ' (' . $code . ')';
    }
    if ($assignedCustomerId !== null && (int) $customerOption['id'] === $assignedCustomerId) {
      $assignedCustomerLabel = $label;
    }
    if ($effectiveCustomerId !== null && (int) $customerOption['id'] === $effectiveCustomerId) {
      $effectiveCustomerLabel = $label;
    }
  }
}

$messages = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Admin</title>
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
            <h1>Admin Dashboard</h1>
            <p class="muted">Manage uploads, processing, and admin tasks.</p>
          </div>
          <div class="admin-customer-view-control">
            <p class="muted">Current view: <?php echo hub_h($effectiveCustomerLabel); ?><?php echo $overrideCustomerId !== null ? " (temporary)" : ""; ?></p>
            <div class="links">
              <button type="button" id="openCustomerSwitchModal">Change Customer</button>
              <form method="post" action="/admin.php" style="margin:0; display:contents;">
                <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                <input type="hidden" name="action" value="reset_customer_override">
                <button type="submit">Reset Customer</button>
              </form>
            </div>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($actionError): ?>
          <div class="alert error"><?php echo hub_h($actionError); ?></div>
        <?php endif; ?>
        <?php if ($customerSwitchError): ?>
          <div class="alert error"><?php echo hub_h($customerSwitchError); ?></div>
        <?php endif; ?>

        <?php echo hub_admin_render_tool_cards('admin', $currentUser); ?>
      </div>

      <div class="card" id="admin-actions">
        <div class="flex">
          <div>
            <p class="brand">Actions</p>
            <h2>Admin Action List</h2>
            <p class="muted"><?php echo number_format($openActions); ?> open items created by imports or admin workflows.</p>
          </div>
          <div class="links">
            <?php if ($showAllActions): ?>
              <a href="/admin.php">Mine + Unassigned</a>
            <?php else: ?>
              <a href="/admin.php?show_actions=all">Show All</a>
            <?php endif; ?>
          </div>
        </div>

        <?php if (empty($adminActions)): ?>
          <div class="alert info">No open actions found.</div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Assigned</th>
                <th>Priority</th>
                <th>Created</th>
                <th>Options</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($adminActions as $action): ?>
                <?php
                  $entityLabel = (string) $action['entity_type'];
                  if (!empty($action['entity_id'])) {
                    $entityLabel .= ' #' . (int) $action['entity_id'];
                  }
                  $entityUrl = null;
                  if (($action['entity_type'] ?? '') === 'project') {
                    $entityUrl = '/admin/projects.php';
                  } elseif (($action['entity_type'] ?? '') === 'customer') {
                    $entityUrl = '/admin/customers.php';
                  }
                ?>
                <tr>
                  <td><?php echo (int) $action['id']; ?></td>
                  <td>
                    <strong><?php echo hub_h((string) $action['title']); ?></strong>
                    <?php if (!empty($action['message'])): ?>
                      <div class="muted"><?php echo hub_h((string) $action['message']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($action['source'])): ?>
                      <div class="muted">Source: <?php echo hub_h((string) $action['source']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($entityUrl): ?>
                      <a href="<?php echo hub_h($entityUrl); ?>"><?php echo hub_h($entityLabel); ?></a>
                    <?php else: ?>
                      <?php echo hub_h($entityLabel); ?>
                    <?php endif; ?>
                  </td>
                  <td><?php echo !empty($action['assigned_email']) ? hub_h((string) $action['assigned_email']) : '<span class="muted">Everyone</span>'; ?></td>
                  <td><?php echo hub_h((string) $action['priority']); ?></td>
                  <td class="muted"><?php echo hub_h((string) $action['created']); ?></td>
                  <td>
                    <div class="links">
                      <form method="post" action="/admin.php<?php echo $showAllActions ? '?show_actions=all' : ''; ?>" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_admin_action">
                        <input type="hidden" name="action_id" value="<?php echo (int) $action['id']; ?>">
                        <input type="hidden" name="status" value="complete">
                        <button type="submit">Complete</button>
                      </form>
                      <form method="post" action="/admin.php<?php echo $showAllActions ? '?show_actions=all' : ''; ?>" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_admin_action">
                        <input type="hidden" name="action_id" value="<?php echo (int) $action['id']; ?>">
                        <input type="hidden" name="status" value="dismissed">
                        <button type="submit">Dismiss</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="modal<?php echo (($_GET['change_customer'] ?? '') === '1') ? ' open' : ''; ?>" id="customerSwitchModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Change Customer</h2>
        <button class="close-btn but2" id="closeCustomerSwitchModal" aria-label="Close">&times;</button>
      </div>
      <p class="muted">Assigned customer: <?php echo hub_h($assignedCustomerLabel); ?></p>
      <p class="muted">Current front-end view: <?php echo hub_h($effectiveCustomerLabel); ?><?php echo $overrideCustomerId !== null ? ' (temporary)' : ''; ?></p>
      <form method="post" action="/admin.php" style="display:grid; gap:12px; margin-top:12px;">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="change_customer_override">
        <div>
          <label for="customer_override_id">Customer view</label>
          <select id="customer_override_id" name="customer_id">
            <option value="">Use assigned customer: <?php echo hub_h($assignedCustomerLabel); ?></option>
            <?php foreach ($adminCustomerOptions as $customerOption): ?>
              <?php
                $optionLabel = trim((string) ($customerOption['name'] ?? ''));
                $optionCode = trim((string) ($customerOption['code'] ?? ''));
                if ($optionLabel === '') {
                  $optionLabel = $optionCode !== '' ? $optionCode : 'Customer #' . (int) $customerOption['id'];
                }
                if ($optionCode !== '' && $optionCode !== $optionLabel) {
                  $optionLabel .= ' (' . $optionCode . ')';
                }
                $selected = ($overrideCustomerId !== null && (int) $customerOption['id'] === $overrideCustomerId) ? 'selected' : '';
              ?>
              <option value="<?php echo (int) $customerOption['id']; ?>" <?php echo $selected; ?>><?php echo hub_h($optionLabel); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="links" style="justify-content:flex-end;">
          <button type="submit">Apply</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    (function() {
      const modal = document.getElementById('customerSwitchModal');
      const openBtn = document.getElementById('openCustomerSwitchModal');
      const closeBtn = document.getElementById('closeCustomerSwitchModal');
      function openModal(event) {
        if (event) event.preventDefault();
        if (modal) modal.classList.add('open');
      }
      function closeModal(event) {
        if (event) event.preventDefault();
        if (modal) modal.classList.remove('open');
      }
      if (openBtn) openBtn.addEventListener('click', openModal);
      if (closeBtn) closeBtn.addEventListener('click', closeModal);
      if (modal) {
        modal.addEventListener('click', function(event) {
          if (event.target === modal) closeModal(event);
        });
      }
    })();
  </script>
</body>
</html>
