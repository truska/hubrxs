<?php
require_once __DIR__ . '/../includes/app/auth.php';
require_once __DIR__ . '/../includes/app/import_sales_orders.php'; // for hub_table_exists etc.
hub_require_admin();

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();

// Fetch customers for dropdown.
$customerOptions = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
  $stmtCust = $pdo->query('SELECT id, name, code FROM hub_customer WHERE archived = 0 ORDER BY name ASC, code ASC');
  $customerOptions = $stmtCust ? $stmtCust->fetchAll(PDO::FETCH_ASSOC) : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    $email = trim((string) ($_POST['email'] ?? ''));
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
    $customerId = trim((string) ($_POST['customer_id'] ?? '')) === '' ? null : (int) $_POST['customer_id'];

    $data = [
      'email' => $email,
      'display_name' => $display,
      'password' => $password,
      'customer_id' => $customerId,
      'role' => $role,
    ];
    $createErr = null;
    $newId = hub_create_user($data, $createErr);
    if ($newId) {
      hub_flash('success', 'User added (ID ' . $newId . ').');
      hub_redirect('/admin/users.php');
    } else {
      $error = $createErr ?: 'Unable to create user.';
    }
  }
}

// List users.
$users = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_user')) {
  $stmtUsers = $pdo->query(
    'SELECT u.id, u.email, u.display_name, u.role, u.customer_id, u.login_enabled, u.twofa_enabled, u.created, c.code AS customer_code, c.name AS customer_name
     FROM hub_user u
     LEFT JOIN hub_customer c ON c.id = u.customer_id
     ORDER BY u.id DESC
     LIMIT 200'
  );
  $users = $stmtUsers ? $stmtUsers->fetchAll(PDO::FETCH_ASSOC) : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Admin Users</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Admin</p>
            <h1>Users</h1>
            <p class="muted">Create users and attach them to customers.</p>
          </div>
          <div class="links">
            <a href="/admin.php">Back to admin</a>
            <a href="/dashboard.php">Dashboard</a>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/users.php" style="display:grid; gap:12px; margin-top:12px;">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <input type="hidden" name="action" value="add_user">
          <div>
            <label for="email">Email (username) *</label>
            <input id="email" name="email" type="email" required>
          </div>
          <div>
            <label for="display_name">Display name</label>
            <input id="display_name" name="display_name" type="text">
          </div>
          <div>
            <label for="password">Password *</label>
            <input id="password" name="password" type="password" required>
            <p class="muted">Min <?php echo hub_password_min_length(); ?> characters.</p>
          </div>
          <div>
            <label for="customer_id">Customer</label>
            <select id="customer_id" name="customer_id">
              <option value="">-- None / admin only --</option>
              <?php foreach ($customerOptions as $opt): ?>
                <?php
                  $label = trim((string) ($opt['name'] ?? ''));
                  $code = (string) ($opt['code'] ?? '');
                  $label = $label !== '' ? $label . ' (' . $code . ')' : $code;
                ?>
                <option value="<?php echo hub_h((string) $opt['id']); ?>">
                  <?php echo hub_h($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="role">Role</label>
            <select id="role" name="role">
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="links" style="justify-content:flex-start;">
            <button type="submit">Add user</button>
          </div>
        </form>
      </div>

      <div class="card">
        <p class="brand">List</p>
        <h2>Existing users</h2>
        <?php if (empty($users)): ?>
          <div class="alert info">No users found.</div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Name</th>
                <th>Role</th>
                <th>Customer</th>
                <th>2FA</th>
                <th>Login</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $usr): ?>
                <tr>
                  <td><?php echo hub_h((string) $usr['id']); ?></td>
                  <td><?php echo hub_h((string) $usr['email']); ?></td>
                  <td><?php echo hub_h((string) $usr['display_name']); ?></td>
                  <td><?php echo hub_h((string) $usr['role']); ?></td>
                  <td><?php echo hub_h((string) ($usr['customer_name'] ?: $usr['customer_code'] ?: '')); ?></td>
                  <td><?php echo ((int) ($usr['twofa_enabled'] ?? 0) === 1) ? 'On' : 'Off'; ?></td>
                  <td><?php echo ((int) ($usr['login_enabled'] ?? 0) === 1) ? 'On' : 'Off'; ?></td>
                  <td class="muted"><?php echo hub_h((string) $usr['created']); ?></td>
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
