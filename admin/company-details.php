<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/company_details.php';
hub_require_super_admin();
$error = null;
$values = [];
foreach (hub_company_fields() as $fields) foreach ($fields as $name => $field) $values[$name] = hub_company_value($name);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!hub_verify_csrf((string) ($_POST['csrf'] ?? ''))) {
    $error = 'Session expired. Please try again.';
  } else {
    $input = is_array($_POST['details'] ?? null) ? $_POST['details'] : [];
    foreach ($values as $name => $value) if (isset($input[$name]) && is_string($input[$name])) $values[$name] = $input[$name];
    try {
      hub_company_save($input);
      hub_flash('success', 'Company details saved.');
      hub_redirect('/admin/company-details.php');
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}
$messages = hub_flash_messages();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Company Details</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page">
<?php echo hub_admin_header('super'); ?>
<div class="stack"><div class="wrap">
  <div class="card">
    <p class="brand">General Content</p><h1>Company Details</h1>
    <p class="muted">Manage the company name, office contacts and website/social links used across the site. Leave optional details blank to hide them.</p>
    <?php foreach ($messages as $message): ?>
      <div class="alert <?php echo hub_h($message['type']); ?>"><?php echo hub_h($message['message']); ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?><div class="alert error"><?php echo hub_h($error); ?></div><?php endif; ?>
  </div>
  <form method="post" class="company-details-form">
    <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
    <?php foreach (hub_company_fields() as $group => $fields): ?>
      <div class="card"><h2><?php echo hub_h($group); ?></h2><div class="form-grid">
      <?php foreach ($fields as $name => [$label, $type]): ?>
        <div>
          <label for="<?php echo hub_h($name); ?>"><?php echo hub_h($label); ?></label>
          <?php if ($type === 'textarea'): ?>
            <textarea id="<?php echo hub_h($name); ?>" name="details[<?php echo hub_h($name); ?>]" rows="6" maxlength="2048"><?php echo hub_h($values[$name]); ?></textarea>
          <?php else: ?>
            <input id="<?php echo hub_h($name); ?>" name="details[<?php echo hub_h($name); ?>]" type="<?php echo hub_h($type); ?>" maxlength="2048" value="<?php echo hub_h($values[$name]); ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div></div>
    <?php endforeach; ?>
    <div class="card"><button class="but1" type="submit">Save company details</button> <a href="/super.php">Back to Super Admin</a></div>
  </form>
</div></div>
</body></html>
