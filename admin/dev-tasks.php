<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/dev_tasks.php';
hub_require_dev_tasks();
$ready = hub_dev_task_ready();
$filters = ['status'=>(string) ($_GET['status'] ?? 'active'), 'q'=>trim((string) ($_GET['q'] ?? '')), 'priority'=>(int) ($_GET['priority'] ?? 0), 'assignee'=>(string) ($_GET['assignee'] ?? ''), 'raised_by'=>(int) ($_GET['raised_by'] ?? 0)];
if ($filters['assignee'] === 'mine') $filters['assignee'] = (string) hub_current_user()['id'];
$sort = (string) ($_GET['sort'] ?? 'priority');
$list = $ready ? hub_dev_task_list($filters, (int) ($_GET['page'] ?? 1), $sort) : ['rows'=>[], 'total'=>0, 'counts'=>[], 'page'=>1, 'pages'=>1];
$assignees = $ready ? hub_dev_task_assignees() : [];
$raisers = [];
if ($ready) {
  $raisers = $pdo->query('SELECT DISTINCT u.id,u.display_name,u.email FROM hub_user u INNER JOIN hub_dev_task t ON t.created_by=u.id ORDER BY u.display_name,u.email')->fetchAll(PDO::FETCH_ASSOC);
}
function hub_dev_tasks_filter_url(array $changes): string {
  return '/admin/dev-tasks.php?' . http_build_query(array_merge($_GET, ['page'=>1], $changes));
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dev Tasks | <?php echo hub_h(HUB_APP_NAME); ?></title><link rel="stylesheet" href="/css/hub.css">
</head><body class="admin-page dev-tasks-page">
<?php echo hub_admin_header(hub_is_developer() ? 'dev' : 'super'); ?>
<div class="stack"><div class="dev-tasks-wrap">
  <div class="card">
    <div class="flex"><div><p class="brand">Tools</p><h1>Dev Tasks</h1><p class="muted">Track questions, faults and development work.</p></div><div class="links"><a href="<?php echo hub_is_developer() ? '/developer.php' : '/super.php'; ?>"><?php echo hub_is_developer() ? 'Developer home' : 'Super Admin home'; ?></a><?php if ($ready): ?><a class="but1" href="/admin/dev-task.php">New task</a><?php endif; ?></div></div>
    <?php foreach (hub_flash_messages() as $message): ?><div class="alert <?php echo hub_h($message['type']); ?>"><?php echo hub_h($message['message']); ?></div><?php endforeach; ?>
  </div>
  <?php if (!$ready): ?><div class="alert info">Dev Tasks storage is not installed yet. Run the Dev Tasks SQL update.</div><?php else: ?>
  <div class="card">
    <nav class="dev-task-status-tabs" aria-label="Task status">
      <a class="<?php echo $filters['status']==='active'?'is-active':''; ?>" href="<?php echo hub_h(hub_dev_tasks_filter_url(['status'=>'active'])); ?>">Active</a>
      <?php foreach (hub_dev_task_statuses() as $key=>$label): ?><a class="<?php echo $filters['status']===$key?'is-active':''; ?>" href="<?php echo hub_h(hub_dev_tasks_filter_url(['status'=>$key])); ?>"><?php echo hub_h($label); ?> <span><?php echo (int) ($list['counts'][$key] ?? 0); ?></span></a><?php endforeach; ?>
      <a class="<?php echo $filters['status']==='all'?'is-active':''; ?>" href="<?php echo hub_h(hub_dev_tasks_filter_url(['status'=>'all'])); ?>">All</a>
    </nav>
    <form method="get" class="dev-task-filters">
      <input type="hidden" name="status" value="<?php echo hub_h($filters['status']); ?>">
      <div><label for="q">Search tasks, notes and comments</label><input id="q" name="q" type="search" value="<?php echo hub_h($filters['q']); ?>"></div>
      <div><label for="priority">Priority</label><select id="priority" name="priority"><option value="">All priorities</option><?php foreach (hub_dev_task_priorities() as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $filters['priority']===$key?'selected':''; ?>><?php echo $key . ' — ' . hub_h($label); ?></option><?php endforeach; ?></select></div>
      <div><label for="assignee">Next action allocated to</label><select id="assignee" name="assignee"><option value="">Anyone</option><option value="unassigned" <?php echo $filters['assignee']==='unassigned'?'selected':''; ?>>Unassigned</option><?php foreach ($assignees as $person): ?><option value="<?php echo (int) $person['id']; ?>" <?php echo (string) $person['id']===$filters['assignee']?'selected':''; ?>><?php echo hub_h(hub_dev_task_user_name($person)); ?></option><?php endforeach; ?></select></div>
      <div><label for="raised_by">Raised by</label><select id="raised_by" name="raised_by"><option value="">Anyone</option><?php foreach ($raisers as $person): ?><option value="<?php echo (int) $person['id']; ?>" <?php echo (int) $person['id']===$filters['raised_by']?'selected':''; ?>><?php echo hub_h(hub_dev_task_user_name($person)); ?></option><?php endforeach; ?></select></div>
      <div><label for="sort">Sort by</label><select id="sort" name="sort"><?php foreach (['priority'=>'Priority', 'updated'=>'Recently updated', 'oldest'=>'Oldest first', 'name'=>'Task name', 'assignee'=>'Next action'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $sort===$key?'selected':''; ?>><?php echo hub_h($label); ?></option><?php endforeach; ?></select></div>
      <div class="links"><button type="submit">Filter</button><a href="/admin/dev-tasks.php">Clear</a><a href="<?php echo hub_h(hub_dev_tasks_filter_url(['assignee'=>'mine'])); ?>">My next actions</a></div>
    </form>
  </div>
  <div class="card">
    <p class="muted"><?php echo number_format($list['total']); ?> task<?php echo $list['total']===1?'':'s'; ?> found. Active includes Open, In Progress, Completed and On Hold.</p>
    <div class="dev-task-table-scroll"><table class="table dev-task-table"><thead><tr><th>Task / raised</th><th>Priority</th><th>Next action</th><th>Raised by</th><th>Last edited by</th><th>Comments</th><th>Updated</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($list['rows'] as $task): ?><tr>
        <td><a class="dev-task-title" href="/admin/dev-task.php?id=<?php echo (int) $task['id']; ?>">#<?php echo (int) $task['id']; ?> — <?php echo hub_h($task['task_name']); ?></a><small class="muted"><?php echo hub_h(hub_dev_task_date($task['created'])); ?></small></td>
        <td><span class="dev-task-badge priority-<?php echo (int) $task['priority']; ?>"><?php echo (int) $task['priority'] . ' — ' . hub_h(hub_dev_task_priorities()[(int) $task['priority']]); ?></span></td>
        <td><?php echo hub_h(hub_dev_task_person($task,'assignee')); ?></td><td><?php echo hub_h(hub_dev_task_person($task,'creator')); ?></td><td><?php echo hub_h(hub_dev_task_person($task,'editor')); ?></td><td><?php echo (int) $task['comment_count']; ?></td><td><?php echo hub_h(hub_dev_task_date($task['modified'])); ?></td><td><span class="dev-task-badge status-<?php echo hub_h($task['status']); ?>"><?php echo hub_h(hub_dev_task_statuses()[$task['status']]); ?></span></td>
      </tr><?php endforeach; ?>
      <?php if (!$list['rows']): ?><tr><td colspan="8">No tasks match these filters.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($list['pages']>1): ?><nav class="links" aria-label="Task pages"><?php if ($list['page']>1): ?><a href="<?php echo hub_h(hub_dev_tasks_filter_url(['page'=>$list['page']-1])); ?>">Previous</a><?php endif; ?><span>Page <?php echo $list['page']; ?> of <?php echo $list['pages']; ?></span><?php if ($list['page']<$list['pages']): ?><a href="<?php echo hub_h(hub_dev_tasks_filter_url(['page'=>$list['page']+1])); ?>">Next</a><?php endif; ?></nav><?php endif; ?>
  </div>
  <?php endif; ?>
</div></div></body></html>
