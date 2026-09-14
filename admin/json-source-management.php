<?php
require_once __DIR__ . '/../includes/app/imports/remote_sources.php';
require_once __DIR__ . '/../includes/app/help.php';
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_developer();

$editId = (int) ($_GET['edit'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid request.'); }
  try {
    $sourceId = (int) ($_POST['source_id'] ?? 0);
    $existing = $sourceId ? hub_import_source_by_id($sourceId) : null;
    if ($sourceId && !$existing) throw new RuntimeException('Import source was not found.');
    $input = [
      'import_key' => $existing['import_key'] ?? ($_POST['import_key'] ?? ''),
      'name' => $_POST['name'] ?? '', 'source_url' => $_POST['source_url'] ?? '',
      'handler' => $_POST['handler'] ?? 'generic_json', 'auth_type' => $_POST['auth_type'] ?? 'basic',
      'auth_username' => $_POST['auth_username'] ?? '', 'auth_password' => $_POST['auth_password'] ?? '',
      'clear_auth_password' => !empty($_POST['clear_auth_password']), 'notes' => $_POST['notes'] ?? '',
      'timeout_seconds' => $_POST['timeout_seconds'] ?? 60, 'show_on_web' => !empty($_POST['show_on_web']), 'archived' => !empty($_POST['archived']),
    ];
    $savedId = hub_import_upsert_source($input);
    hub_log_user_action(['action_key' => $existing ? 'import_source_updated' : 'import_source_created', 'action_title' => $existing ? 'Import source updated' : 'Import source created', 'table_name' => 'hub_import_source', 'record_id' => $savedId, 'details' => ['import_key' => $input['import_key'], 'source_url' => $input['source_url'], 'password_replaced' => $input['auth_password'] !== '', 'password_cleared' => $input['clear_auth_password']]]);
    hub_flash('success', 'Source saved. Passwords are encrypted and are never displayed.');
    hub_redirect('/admin/json-source-management.php?edit=' . $savedId);
  } catch (Throwable $e) {
    hub_flash('error', $e->getMessage());
    hub_redirect('/admin/json-source-management.php' . ($editId ? '?edit=' . $editId : ''));
  }
}
$sources = hub_import_list_sources(true);
$editing = $editId ? hub_import_source_by_id($editId) : null;
$messages = hub_flash_messages();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?php echo hub_h(HUB_APP_NAME); ?> | JSON Source Management</title><?php echo hub_fontawesome_css(); ?><link rel="stylesheet" href="/css/hub.css"></head><body class="admin-page">
<?php echo hub_admin_header('dev'); ?><div class="stack"><div class="stack"><div class="card"><div class="flex"><div><p class="brand">Developer</p><h1>JSON source management</h1><p class="muted">Manage source URLs and credentials. Import running remains on the Import Sources page.</p></div><div class="links"><a href="/developer.php">Developer home</a><a href="/admin/import-sources.php">Run imports</a><a class="but1" href="/admin/json-source-management.php">Add source</a></div></div>
<?php foreach ($messages as $msg): ?><div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div><?php endforeach; ?>
<?php if (!$sources): ?><div class="alert info">No JSON sources are configured yet.</div><?php else: ?><table class="table"><thead><tr><th>Name / key</th><th>Source URL</th><th>Username</th><th>Password</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($sources as $source): ?><tr><td><strong><?php echo hub_h($source['name']); ?></strong><div class="muted"><?php echo hub_h($source['import_key']); ?> · <?php echo hub_h($source['handler']); ?></div></td><td class="import-result-cell"><a href="<?php echo hub_h($source['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo hub_h($source['source_url']); ?></a></td><td><?php echo hub_h($source['auth_username'] ?: '—'); ?></td><td><?php echo !empty($source['auth_password_ciphertext']) ? 'Encrypted (set)' : 'Not set'; ?></td><td><?php echo !empty($source['archived']) ? 'Archived' : 'Active'; ?></td><td><a class="but2" href="/admin/json-source-management.php?edit=<?php echo (int) $source['id']; ?>">Edit</a></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<div class="card"><p class="brand"><?php echo $editing ? 'Edit source' : 'New source'; ?></p><h2><?php echo $editing ? hub_h($editing['name']) : 'Add a JSON source'; ?></h2><form method="post" action="/admin/json-source-management.php<?php echo $editing ? '?edit=' . (int) $editing['id'] : ''; ?>"><input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>"><?php if ($editing): ?><input type="hidden" name="source_id" value="<?php echo (int) $editing['id']; ?>"><?php endif; ?><div class="form-grid"><div><label for="name">Name *</label><input id="name" name="name" required value="<?php echo hub_h($editing['name'] ?? ''); ?>"></div><div><label for="import_key">Import key *</label><input id="import_key" name="import_key" required <?php echo $editing ? 'readonly' : ''; ?> value="<?php echo hub_h($editing['import_key'] ?? ''); ?>"><p class="muted">Fixed after creation to keep existing imports matched.</p></div><div><label for="source_url">JSON source URL *</label><input id="source_url" type="url" name="source_url" required value="<?php echo hub_h($editing['source_url'] ?? ''); ?>"></div><div><label for="handler">Handler</label><select id="handler" name="handler"><option value="generic_json">Generic JSON</option><option value="portal_inventory" <?php echo ($editing['handler'] ?? '') === 'portal_inventory' ? 'selected' : ''; ?>>Portal inventory</option><option value="so_portal_lines" <?php echo ($editing['handler'] ?? '') === 'so_portal_lines' ? 'selected' : ''; ?>>Sales order portal lines</option></select></div><div><label for="auth_type">Authentication</label><select id="auth_type" name="auth_type"><option value="basic" <?php echo ($editing['auth_type'] ?? 'basic') === 'basic' ? 'selected' : ''; ?>>HTTP Basic</option><option value="none" <?php echo ($editing['auth_type'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option></select></div><div><label for="auth_username">Username</label><input id="auth_username" name="auth_username" autocomplete="off" value="<?php echo hub_h($editing['auth_username'] ?? ''); ?>"></div><div><label for="auth_password">Password <?php echo $editing ? '(blank keeps current)' : ''; ?></label><input id="auth_password" type="password" name="auth_password" autocomplete="new-password"><label class="muted"><input type="checkbox" name="clear_auth_password" value="1"> Clear saved password</label></div><div><label for="timeout_seconds">Timeout (seconds)</label><input id="timeout_seconds" type="number" min="5" max="300" name="timeout_seconds" value="<?php echo (int) ($editing['timeout_seconds'] ?? 60); ?>"></div></div><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"><?php echo hub_h($editing['notes'] ?? ''); ?></textarea><p><label><input type="checkbox" name="show_on_web" value="1" <?php echo !isset($editing['show_on_web']) || !empty($editing['show_on_web']) ? 'checked' : ''; ?>> Visible in dashboard</label> <label><input type="checkbox" name="archived" value="1" <?php echo !empty($editing['archived']) ? 'checked' : ''; ?>> Archive source</label></p><div class="links"><button class="but1" type="submit">Save source</button><?php if ($editing): ?><a href="/admin/json-source-management.php">Cancel</a><?php endif; ?></div></form></div></div></div></body></html>
