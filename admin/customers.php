<?php
require_once __DIR__ . '/../includes/app/auth.php';
hub_require_admin();

global $pdo, $DB_OK;

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    $code = trim((string) ($_POST['code'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['contact_email'] ?? ''));

    if ($code === '' || $name === '') {
      $error = 'Code and name are required.';
    } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_customer')) {
      $error = 'Database not available.';
    } else {
      try {
        $stmt = $pdo->prepare('INSERT INTO hub_customer (code, name, contact_email, archived, created, modified) VALUES (:code, :name, :email, 0, NOW(), NOW())');
        $stmt->execute([
          ':code' => $code,
          ':name' => $name,
          ':email' => $email !== '' ? $email : null,
        ]);
        hub_flash('success', 'Customer added.');
        hub_redirect('/admin/customers.php');
      } catch (PDOException $e) {
        $error = 'Unable to add customer (maybe duplicate code).';
      }
    }
  }
}

$customers = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
  $stmt = $pdo->query('SELECT id, code, name, contact_email, archived, created FROM hub_customer ORDER BY name ASC, id ASC');
  $customers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$messages = hub_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Admin Customers</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Admin</p>
            <h1>Customers</h1>
            <p class="muted">Add or review customers for user assignment and data visibility.</p>
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

        <form method="post" action="/admin/customers.php" style="display:grid; gap:12px; margin-top:12px;">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
          <input type="hidden" name="action" value="add_customer">
          <div>
            <label for="code">Code *</label>
            <input id="code" name="code" type="text" required>
          </div>
          <div>
            <label for="name">Name *</label>
            <input id="name" name="name" type="text" required>
          </div>
          <div>
            <label for="contact_email">Contact email</label>
            <input id="contact_email" name="contact_email" type="email" placeholder="optional">
          </div>
          <div class="links" style="justify-content:flex-start;">
            <button type="submit">Add customer</button>
          </div>
        </form>
      </div>

      <div class="card">
        <p class="brand">List</p>
        <h2>Existing customers</h2>
        <?php if (empty($customers)): ?>
          <div class="alert info">No customers found.</div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Code</th>
                <th>Name</th>
                <th>Contact</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($customers as $cust): ?>
                <tr>
                  <td><?php echo hub_h((string) $cust['id']); ?></td>
                  <td><?php echo hub_h((string) $cust['code']); ?></td>
                  <td><?php echo hub_h((string) $cust['name']); ?></td>
                  <td>
                    <?php if (!empty($cust['contact_email'])): ?>
                      <a href="mailto:<?php echo hub_h((string) $cust['contact_email']); ?>"><?php echo hub_h((string) $cust['contact_email']); ?></a>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="muted"><?php echo hub_h((string) $cust['created']); ?></td>
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
