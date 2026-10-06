<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/dev_tasks.php';
hub_require_dev_tasks();
$ready = hub_dev_task_ready();
$columns = ['id'=>'Task ID', 'priority'=>'Priority', 'name'=>'Task', 'assignee'=>'Next action by', 'raised_by'=>'Raised by', 'updated_by'=>'Last edited by', 'conversation'=>'Conversation', 'updated'=>'Updated', 'status'=>'Status'];
$columnFilters = [];
$inputFilters = is_array($_GET['col'] ?? null) ? $_GET['col'] : [];
foreach ($columns as $key=>$label) $columnFilters[$key] = isset($inputFilters[$key]) && is_scalar($inputFilters[$key]) ? trim((string) $inputFilters[$key]) : '';
$status = (string) ($_GET['status'] ?? 'active');
if (!in_array($status, array_merge(['active','all'],array_keys(hub_dev_task_statuses())),true)) $status='active';
$sort = (string) ($_GET['sort'] ?? 'priority_asc');
if (!preg_match('/^(' . implode('|',array_keys($columns)) . ')_(asc|desc)$/', $sort)) $sort='priority_asc';
$list = $ready ? hub_dev_task_list(['status'=>$status,'col'=>$columnFilters], (int) ($_GET['page'] ?? 1), $sort) : ['rows'=>[], 'total'=>0, 'counts'=>[], 'page'=>1, 'pages'=>1];
$people = ['assignee'=>[], 'raised_by'=>[], 'updated_by'=>[]];
if ($ready) {
  foreach (['assignee'=>'next_action_by','raised_by'=>'created_by','updated_by'=>'updated_by'] as $key=>$field) {
    $people[$key]=$pdo->query('SELECT DISTINCT u.id,u.display_name,u.email FROM hub_user u INNER JOIN hub_dev_task t ON t.' . $field . '=u.id ORDER BY u.display_name,u.email')->fetchAll(PDO::FETCH_ASSOC);
  }
}
function hub_dev_tasks_filter_url(array $changes): string {
  global $status,$sort,$columnFilters;
  return '/admin/dev-tasks.php?' . http_build_query(array_merge(['status'=>$status,'sort'=>$sort,'col'=>$columnFilters,'page'=>1],$changes));
}
function hub_dev_tasks_heading(string $key, string $label): string {
  global $sort;
  $ascending=$key . '_asc'; $descending=$key . '_desc';
  $next=$sort===$ascending ? $descending : $ascending;
  $arrow=$sort===$ascending ? ' ▲' : ($sort===$descending ? ' ▼' : ' ↕');
  return '<a href="' . hub_h(hub_dev_tasks_filter_url(['sort'=>$next])) . '">' . hub_h($label) . $arrow . '</a>';
}
$counts=$list['counts'];
$activeCount=0;
foreach (['open','in_progress','completed','on_hold'] as $key) $activeCount+=(int) ($counts[$key] ?? 0);
$statusCounts=['active'=>$activeCount]+$counts+['all'=>array_sum($counts)];
$statusLabels=['active'=>'Active']+hub_dev_task_statuses()+['all'=>'All'];
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
  <div class="dev-task-list-toolbar">
    <nav class="dev-task-status-tabs" aria-label="Task status">
      <?php foreach ($statusLabels as $key=>$label): ?><a class="<?php echo $status===$key?'is-active':''; ?>" href="<?php echo hub_h(hub_dev_tasks_filter_url(['status'=>$key,'col'=>array_merge($columnFilters,['status'=>''])])); ?>" <?php echo $status===$key?'aria-current="page"':''; ?>><?php echo hub_h($label); ?> <span><?php echo (int) ($statusCounts[$key] ?? 0); ?></span></a><?php endforeach; ?>
    </nav>
    <div class="links"><a href="/admin/dev-tasks.php">Clear filters</a></div>
  </div>
  <div class="card">
    <p class="muted dev-task-record-count"><?php echo number_format($list['total']); ?> task<?php echo $list['total']===1?'':'s'; ?></p>
    <form method="get" id="dev-task-filter-form">
      <input type="hidden" name="status" value="<?php echo hub_h($status); ?>"><input type="hidden" name="sort" value="<?php echo hub_h($sort); ?>"><input type="hidden" name="page" value="1">
    </form>
    <div class="dev-task-table-scroll"><table class="table dev-task-table import-data-table"><thead>
      <tr><?php foreach ($columns as $key=>$label): ?><th scope="col" <?php echo $sort===$key.'_asc'?'aria-sort="ascending"':($sort===$key.'_desc'?'aria-sort="descending"':''); ?>><?php echo hub_dev_tasks_heading($key,$label); ?></th><?php endforeach; ?></tr>
      <tr class="import-data-filter-row">
        <?php foreach ($columns as $key=>$label): ?><th>
          <?php if (in_array($key,['id','name','conversation','updated'],true)): ?>
            <input type="search" name="col[<?php echo $key; ?>]" form="dev-task-filter-form" data-auto-filter <?php echo $key==='id'?'data-filter-min-length="1"':''; ?> value="<?php echo hub_h($columnFilters[$key]); ?>" placeholder="<?php echo $key==='id'?'ID':'Search '.strtolower($label); ?>" aria-label="Filter <?php echo hub_h($label); ?>">
          <?php else: ?>
            <select name="col[<?php echo $key; ?>]" form="dev-task-filter-form" data-auto-filter aria-label="Filter <?php echo hub_h($label); ?>"><option value="">All</option>
              <?php if ($key==='priority' || $key==='status'): ?>
                <?php foreach ($key==='priority'?hub_dev_task_priorities():hub_dev_task_statuses() as $value=>$option): ?><option value="<?php echo hub_h((string) $value); ?>" <?php echo $columnFilters[$key]===(string)$value?'selected':''; ?>><?php echo hub_h($key==='priority'?$value.' — '.$option:$option); ?></option><?php endforeach; ?>
              <?php else: ?>
                <?php if ($key==='assignee'): ?><option value="unassigned" <?php echo $columnFilters[$key]==='unassigned'?'selected':''; ?>>Unassigned</option><?php endif; ?>
                <?php foreach ($people[$key] as $person): ?><option value="<?php echo (int) $person['id']; ?>" <?php echo $columnFilters[$key]===(string)$person['id']?'selected':''; ?>><?php echo hub_h(hub_dev_task_user_name($person)); ?></option><?php endforeach; ?>
              <?php endif; ?>
            </select>
          <?php endif; ?>
        </th><?php endforeach; ?>
      </tr>
    </thead>
      <?php foreach ($list['rows'] as $index=>$task): ?><tbody class="dev-task-group <?php echo $index%2===0?'dev-task-group-striped':''; ?>">
        <tr class="dev-task-title-row"><td colspan="9"><a class="dev-task-title" href="/admin/dev-task.php?id=<?php echo (int) $task['id']; ?>"><?php echo hub_h($task['task_name']); ?></a></td></tr>
        <tr class="dev-task-details-row">
          <td><?php echo (int) $task['id']; ?></td><td><span class="dev-task-badge priority-<?php echo (int) $task['priority']; ?>" title="<?php echo hub_h(hub_dev_task_priorities()[(int) $task['priority']]); ?>">P<?php echo (int) $task['priority']; ?></span></td>
          <td><?php echo hub_h(hub_dev_task_date($task['created'])); ?></td><td><?php echo hub_h(hub_dev_task_person($task,'assignee')); ?></td><td><?php echo hub_h(hub_dev_task_person($task,'creator')); ?></td><td><?php echo hub_h(hub_dev_task_person($task,'editor')); ?></td><td><?php echo (int) $task['comment_count']; ?> comment<?php echo (int) $task['comment_count']===1?'':'s'; ?></td><td><?php echo hub_h(hub_dev_task_date($task['modified'])); ?></td><td><span class="dev-task-badge status-<?php echo hub_h($task['status']); ?>"><?php echo hub_h(hub_dev_task_statuses()[$task['status']]); ?></span></td>
        </tr>
      </tbody><?php endforeach; ?>
      <?php if (!$list['rows']): ?><tbody><tr><td colspan="9">No tasks match these filters.</td></tr></tbody><?php endif; ?>
    </table></div>
    <noscript><p><button type="submit" form="dev-task-filter-form">Apply column filters</button></p></noscript>
    <?php if ($list['pages']>1): ?><nav class="links" aria-label="Task pages"><?php if ($list['page']>1): ?><a href="<?php echo hub_h(hub_dev_tasks_filter_url(['page'=>$list['page']-1])); ?>">Previous</a><?php endif; ?><span>Page <?php echo $list['page']; ?> of <?php echo $list['pages']; ?></span><?php if ($list['page']<$list['pages']): ?><a href="<?php echo hub_h(hub_dev_tasks_filter_url(['page'=>$list['page']+1])); ?>">Next</a><?php endif; ?></nav><?php endif; ?>
  </div>
  <?php endif; ?>
</div></div><script src="/js/hub.js?v=20261006-table-filters"></script></body></html>
