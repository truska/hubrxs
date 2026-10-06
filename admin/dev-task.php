<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/dev_tasks.php';
hub_require_dev_tasks();
$user = hub_current_user();
$ready = hub_dev_task_ready();
$id = max(0, (int) ($_GET['id'] ?? $_POST['task_id'] ?? 0));
$error = null;
$action = (string) ($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!hub_verify_csrf((string) ($_POST['csrf'] ?? ''))) $error='Session expired. Please try again.';
  elseif (!$ready) $error='Dev Tasks storage is not installed yet.';
  elseif (($action === 'create' && $id !== 0) || ($action !== 'create' && $id === 0)) $error='Choose a valid task action.';
  else {
    try {
      $id = hub_dev_task_save($action, $id, $_POST, $_FILES['image'] ?? [], $user);
      hub_flash('success', ['create'=>'Task created.', 'save_task'=>'Task updated.', 'save_notes'=>'Task notes saved.', 'comment'=>'Comment added.'][$action] ?? 'Task saved.');
      hub_redirect('/admin/dev-task.php?id=' . $id . ($action==='save_notes'?'#task-notes':($action==='comment'?'#reply':'')));
    } catch (PDOException $e) { $error='The task could not be saved. Please try again.'; }
    catch (RuntimeException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { $error='The task could not be saved. Please try again.'; }
  }
}
$task = $ready && $id ? hub_dev_task_get($id) : null;
if ($ready && $id && !$task) { http_response_code(404); $error='This task could not be found.'; }
$comments = [];
$assignees = $ready ? hub_dev_task_assignees() : [];
if ($task) {
  $stmt=$pdo->prepare('SELECT * FROM hub_dev_task_comment WHERE task_id=? ORDER BY created ASC,id ASC');
  $stmt->execute([$id]); $comments=$stmt->fetchAll(PDO::FETCH_ASSOC);
}
$fields = $task ?: ['task_name'=>'', 'priority'=>3, 'status'=>'open', 'next_action_by'=>null, 'task_note'=>''];
$taskVersion = $error && $action==='save_task' ? (int) ($_POST['version'] ?? 0) : (int) ($task['version'] ?? 0);
$noteVersion = $error && $action==='save_notes' ? (int) ($_POST['version'] ?? 0) : (int) ($task['version'] ?? 0);
if ($error && in_array($action,['create','save_task'],true)) foreach (['task_name','priority','status','next_action_by'] as $name) $fields[$name]=$_POST[$name] ?? $fields[$name];
function hub_dev_task_form_fields(array $fields, array $assignees): void {
  ?>
  <div class="dev-task-fields">
    <div class="dev-task-name-field"><label for="task_name">Task name *</label><input id="task_name" name="task_name" maxlength="255" required value="<?php echo hub_h((string) $fields['task_name']); ?>"></div>
    <div><label for="priority">Priority</label><select id="priority" name="priority"><?php foreach (hub_dev_task_priorities() as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo (int) $fields['priority']===$key?'selected':''; ?>><?php echo $key . ' — ' . hub_h($label); ?></option><?php endforeach; ?></select></div>
    <div><label for="status">Status</label><select id="status" name="status"><?php foreach (hub_dev_task_statuses() as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $fields['status']===$key?'selected':''; ?>><?php echo hub_h($label); ?></option><?php endforeach; ?></select></div>
    <div><label for="next_action_by">Next action allocated to</label><select id="next_action_by" name="next_action_by"><option value="">Unassigned</option><?php foreach ($assignees as $person): ?><option value="<?php echo (int) $person['id']; ?>" <?php echo (int) $fields['next_action_by']===(int) $person['id']?'selected':''; ?>><?php echo hub_h(hub_dev_task_user_name($person)); ?></option><?php endforeach; ?><?php if (!empty($fields['next_action_by']) && !in_array((int) $fields['next_action_by'], array_map('intval',array_column($assignees,'id')),true)): ?><option value="<?php echo (int) $fields['next_action_by']; ?>" selected>Previous assignee (unavailable)</option><?php endif; ?></select></div>
  </div>
  <?php
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo hub_h($task ? 'Dev Task #' . $id : 'New Dev Task'); ?> | <?php echo hub_h(HUB_APP_NAME); ?></title><link rel="stylesheet" href="/css/hub.css">
</head><body class="admin-page dev-tasks-page">
<?php echo hub_admin_header(hub_is_developer() ? 'dev' : 'super'); ?>
<div class="stack"><div class="dev-tasks-wrap">
  <div class="card"><div class="flex"><div><p class="brand"><?php echo $task ? 'Dev Task #' . $id : 'Tools'; ?></p><h1><?php echo hub_h($task ? $task['task_name'] : 'New Dev Task'); ?></h1></div><div class="links"><a href="/admin/dev-tasks.php">Back to tasks</a></div></div>
    <?php foreach (hub_flash_messages() as $message): ?><div class="alert <?php echo hub_h($message['type']); ?>"><?php echo hub_h($message['message']); ?></div><?php endforeach; ?>
    <?php if ($error): ?><div class="alert error" role="alert"><?php echo hub_h($error); ?></div><?php endif; ?>
  </div>
  <?php if (!$ready): ?><div class="alert info">Dev Tasks storage is not installed yet. Run the Dev Tasks SQL update.</div>
  <?php elseif (!$id): ?>
    <div class="card"><form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>"><input type="hidden" name="action" value="create">
      <?php hub_dev_task_form_fields($fields,$assignees); ?>
      <label for="message">First comment / description</label><textarea id="message" name="message" rows="7"><?php echo hub_h((string) ($_POST['message'] ?? '')); ?></textarea>
      <label for="task_note">Task notes</label><textarea id="task_note" name="task_note" rows="5"><?php echo hub_h((string) ($_POST['task_note'] ?? '')); ?></textarea><p class="muted">A separate, shared note for key details and the current plan.</p>
      <label for="image">Screenshot for the first comment (optional)</label><input id="image" name="image" type="file" accept="image/jpeg,image/png,image/gif,image/webp"><p class="muted">JPG, PNG, GIF or WebP; up to 8 MB and 25 megapixels.</p>
      <button class="but1" type="submit">Create task</button>
    </form></div>
  <?php elseif ($task): ?>
    <div class="card">
      <div class="dev-task-meta"><span class="dev-task-badge status-<?php echo hub_h($task['status']); ?>"><?php echo hub_h(hub_dev_task_statuses()[$task['status']]); ?></span><span>Raised by <strong><?php echo hub_h(hub_dev_task_person($task,'creator')); ?></strong> · <?php echo hub_h(hub_dev_task_date($task['created'])); ?></span><span>Last edited by <strong><?php echo hub_h(hub_dev_task_person($task,'editor')); ?></strong> · <?php echo hub_h(hub_dev_task_date($task['modified'])); ?></span></div>
      <form method="post"><input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>"><input type="hidden" name="action" value="save_task"><input type="hidden" name="task_id" value="<?php echo $id; ?>"><input type="hidden" name="version" value="<?php echo $taskVersion; ?>">
        <?php hub_dev_task_form_fields($fields,$assignees); ?>
        <p class="muted">Completed means ready for review; Closed means signed off.</p><button class="but1" type="submit">Update task</button>
      </form>
    </div>
    <div class="dev-task-detail-grid">
      <section class="dev-task-conversation" aria-label="Task conversation">
        <div class="card"><h2>Comments and progress</h2><p class="muted">Comments appear in the order they were added.</p></div>
        <?php foreach ($comments as $comment): ?>
          <article class="card dev-task-comment <?php echo $comment['is_activity']?'dev-task-activity':''; ?>" id="comment-<?php echo (int) $comment['id']; ?>">
            <div class="dev-task-comment-heading"><strong><?php echo hub_h($comment['author_name']); ?></strong><time datetime="<?php echo hub_h(str_replace(' ','T',$comment['created'])); ?>"><?php echo hub_h(hub_dev_task_date($comment['created'])); ?></time></div>
            <?php if ($comment['is_activity']): ?><p class="muted">Task update</p><?php endif; ?>
            <div class="dev-task-message"><?php echo hub_h($comment['message']); ?></div>
            <?php if ($comment['image_filename']): ?><a class="dev-task-image-link" href="/admin/dev-task-image.php?id=<?php echo (int) $comment['id']; ?>" target="_blank" rel="noopener"><img src="/admin/dev-task-image.php?id=<?php echo (int) $comment['id']; ?>" alt="Screenshot attached by <?php echo hub_h($comment['author_name']); ?>" loading="lazy"></a><?php endif; ?>
          </article>
        <?php endforeach; ?>
        <div class="card" id="reply"><h2>Add a comment</h2><form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>"><input type="hidden" name="action" value="comment"><input type="hidden" name="task_id" value="<?php echo $id; ?>">
          <label for="message">Comment</label><textarea id="message" name="message" rows="6"><?php echo $error && $action==='comment' ? hub_h((string) ($_POST['message'] ?? '')) : ''; ?></textarea>
          <label for="image">Screenshot (optional)</label><input id="image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><p class="muted">JPG, PNG, GIF or WebP; up to 8 MB and 25 megapixels. Add a comment, an image, or both.</p><button class="but1" type="submit">Add comment</button>
        </form></div>
      </section>
      <aside class="card dev-task-notes" id="task-notes"><h2>Task notes</h2><p class="muted">Keep the plan and key details here, separate from the conversation.</p><form method="post">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>"><input type="hidden" name="action" value="save_notes"><input type="hidden" name="task_id" value="<?php echo $id; ?>"><input type="hidden" name="version" value="<?php echo $noteVersion; ?>">
        <label class="sr-only" for="task_note">Task notes</label><textarea id="task_note" name="task_note" rows="14"><?php echo hub_h($error && $action==='save_notes' ? (string) ($_POST['task_note'] ?? '') : $task['task_note']); ?></textarea><button class="but1" type="submit">Save notes</button>
      </form></aside>
    </div>
  <?php endif; ?>
</div></div></body></html>
