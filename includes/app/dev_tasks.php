<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/images.php';

// Change this one setting to super_admin when the module is opened to that role.
function hub_dev_task_minimum_role(): string { return 'developer'; }

function hub_require_dev_tasks(): void {
  hub_require_login();
  if (!hub_role_at_least(hub_dev_task_minimum_role())) {
    http_response_code(403);
    exit('Dev Tasks is not available for your user role.');
  }
}

function hub_dev_task_ready(): bool {
  global $pdo, $DB_OK;
  return $DB_OK && $pdo instanceof PDO && hub_table_exists('hub_dev_task') && hub_table_exists('hub_dev_task_comment');
}

function hub_dev_task_statuses(): array {
  return ['open'=>'Open', 'in_progress'=>'In Progress', 'completed'=>'Completed', 'closed'=>'Closed', 'future'=>'Future', 'on_hold'=>'On Hold'];
}

function hub_dev_task_priorities(): array {
  return [1=>'Urgent', 2=>'High', 3=>'Normal', 4=>'Low', 5=>'When possible'];
}

function hub_dev_task_user_name(array $user): string {
  return trim((string) ($user['display_name'] ?? '')) ?: (trim((string) ($user['email'] ?? '')) ?: 'User #' . (int) ($user['id'] ?? 0));
}

function hub_dev_task_assignees(): array {
  global $pdo;
  $roles = [];
  foreach (hub_role_hierarchy() as $role => $rank) if ($rank >= hub_role_rank(hub_dev_task_minimum_role())) $roles[] = $role;
  $stmt = $pdo->prepare('SELECT id, display_name, email FROM hub_user WHERE archived=0 AND login_enabled=1 AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ') ORDER BY display_name, email, id');
  $stmt->execute($roles);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hub_dev_task_get(int $id): ?array {
  global $pdo;
  $stmt = $pdo->prepare('SELECT t.*, c.display_name AS creator_name, c.email AS creator_email, u.display_name AS editor_name, u.email AS editor_email, a.display_name AS assignee_name, a.email AS assignee_email FROM hub_dev_task t LEFT JOIN hub_user c ON c.id=t.created_by LEFT JOIN hub_user u ON u.id=t.updated_by LEFT JOIN hub_user a ON a.id=t.next_action_by WHERE t.id=?');
  $stmt->execute([$id]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hub_dev_task_validate(array $input): array {
  $name = trim((string) ($input['task_name'] ?? ''));
  if ($name === '' || mb_strlen($name) > 255) throw new RuntimeException('Enter a task name of up to 255 characters.');
  $priority = (string) ($input['priority'] ?? '3');
  if (!in_array($priority, ['1','2','3','4','5'], true)) throw new RuntimeException('Select a priority between 1 and 5.');
  $status = (string) ($input['status'] ?? 'open');
  if (!isset(hub_dev_task_statuses()[$status])) throw new RuntimeException('Select a valid task status.');
  $assignee = (string) ($input['next_action_by'] ?? '');
  $assigneeId = null;
  if ($assignee !== '') {
    if (!ctype_digit($assignee) || !in_array((int) $assignee, array_map('intval', array_column(hub_dev_task_assignees(), 'id')), true)) throw new RuntimeException('Allocate the next action to an active user with access to Dev Tasks, or select Unassigned.');
    $assigneeId = (int) $assignee;
  }
  return ['task_name'=>$name, 'priority'=>(int) $priority, 'status'=>$status, 'next_action_by'=>$assigneeId];
}

function hub_dev_task_text(string $text, string $label): string {
  $text = trim(str_replace("\r\n", "\n", $text));
  if (strlen($text) > 60000) throw new RuntimeException($label . ' is too long. Please use fewer than 60,000 bytes.');
  return $text;
}

function hub_dev_task_image_dir(): string { return dirname(__DIR__, 3) . '/private/dev-task-images'; }

function hub_dev_task_upload(array $file): ?string {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('The screenshot could not be uploaded. Please try again.');
  $tmp = (string) ($file['tmp_name'] ?? '');
  if (!is_uploaded_file($tmp)) throw new RuntimeException('The uploaded screenshot was not received correctly.');
  if (filesize($tmp) > 8 * 1024 * 1024) throw new RuntimeException('Please choose an image smaller than 8 MB.');
  $info = @getimagesize($tmp);
  if (!$info || $info[0] * $info[1] > 25000000) throw new RuntimeException('Please choose a valid image of up to 25 megapixels.');
  $error = null;
  $source = hub_image_source_from_upload($file, $error);
  if (!$source) throw new RuntimeException($error ?: 'Please choose a JPG, PNG, GIF or WebP screenshot.');
  $dir = hub_dev_task_image_dir();
  $filename = bin2hex(random_bytes(24)) . '.png';
  try {
    if (!is_dir($dir) && !mkdir($dir, 0770, true)) throw new RuntimeException('Screenshot storage is unavailable.');
    if (!imagepng($source['image'], $dir . '/' . $filename, 6)) throw new RuntimeException('The screenshot could not be saved.');
    chmod($dir . '/' . $filename, 0660);
  } finally {
    imagedestroy($source['image']);
  }
  return $filename;
}

function hub_dev_task_add_thread(int $id, string $message, ?string $image, array $user, bool $activity = false): void {
  global $pdo;
  $stmt = $pdo->prepare('INSERT INTO hub_dev_task_comment(task_id,author_id,author_name,message,image_filename,is_activity) VALUES (?,?,?,?,?,?)');
  $stmt->execute([$id, (int) $user['id'], mb_substr(hub_dev_task_user_name($user), 0, 255), $message, $image, $activity ? 1 : 0]);
}

/** All writes require the same module permission, including direct POST requests. */
function hub_dev_task_save(string $action, int $id, array $input, array $file, array $user): int {
  global $pdo;
  if (!hub_role_at_least(hub_dev_task_minimum_role(), $user)) throw new RuntimeException('You do not have access to Dev Tasks.');
  if (!hub_dev_task_ready()) throw new RuntimeException('Dev Tasks storage is not installed yet.');
  if (!in_array($action, ['create','save_task','save_notes','comment'], true)) throw new RuntimeException('Choose a valid task action.');
  $fields = in_array($action, ['create','save_task'], true) ? hub_dev_task_validate($input) : [];
  $note = in_array($action, ['create','save_notes'], true) ? hub_dev_task_text((string) ($input['task_note'] ?? ''), 'Task notes') : '';
  $message = in_array($action, ['create','comment'], true) ? hub_dev_task_text((string) ($input['message'] ?? ''), 'Comment') : '';
  $image = null;
  $pdo->beginTransaction();
  try {
    if ($action !== 'create') {
      $stmt = $pdo->prepare('SELECT * FROM hub_dev_task WHERE id=? FOR UPDATE');
      $stmt->execute([$id]);
      $task = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$task) throw new RuntimeException('This task could not be found.');
      if (in_array($action, ['save_task','save_notes'], true) && (int) ($input['version'] ?? 0) !== (int) $task['version']) throw new RuntimeException('This task has changed since you opened it. Reload the page before saving so you can review the latest changes.');
    }
    if (in_array($action, ['create','comment'], true)) {
      $image = hub_dev_task_upload($file);
      if ($message === '' && $image === null && $action === 'comment') throw new RuntimeException('Enter a comment or attach a screenshot.');
    }
    if ($action === 'create') {
      $stmt = $pdo->prepare('INSERT INTO hub_dev_task(task_name,task_note,priority,status,created_by,updated_by,next_action_by) VALUES (?,?,?,?,?,?,?)');
      $stmt->execute([$fields['task_name'], $note, $fields['priority'], $fields['status'], (int) $user['id'], (int) $user['id'], $fields['next_action_by']]);
      $id = (int) $pdo->lastInsertId();
      hub_dev_task_add_thread($id, $message !== '' ? $message : 'Task raised.', $image, $user, $message === '' && $image === null);
    } elseif ($action === 'save_task') {
      $stmt = $pdo->prepare('UPDATE hub_dev_task SET task_name=?,priority=?,status=?,next_action_by=?,updated_by=?,version=version+1,modified=NOW() WHERE id=?');
      $stmt->execute([$fields['task_name'], $fields['priority'], $fields['status'], $fields['next_action_by'], (int) $user['id'], $id]);
      $changes = [];
      if ($fields['task_name'] !== $task['task_name']) $changes[] = 'Task renamed to ' . $fields['task_name'];
      if ($fields['priority'] !== (int) $task['priority']) $changes[] = 'Priority: ' . hub_dev_task_priorities()[$fields['priority']];
      if ($fields['status'] !== $task['status']) $changes[] = 'Status: ' . hub_dev_task_statuses()[$fields['status']];
      if ($fields['next_action_by'] !== ($task['next_action_by'] === null ? null : (int) $task['next_action_by'])) {
        $names = array_column(hub_dev_task_assignees(), null, 'id');
        $changes[] = 'Next action: ' . ($fields['next_action_by'] ? hub_dev_task_user_name($names[$fields['next_action_by']]) : 'Unassigned');
      }
      if ($changes) hub_dev_task_add_thread($id, implode("\n", $changes), null, $user, true);
    } else {
      if ($action === 'save_notes') {
        $stmt = $pdo->prepare('UPDATE hub_dev_task SET task_note=?,updated_by=?,version=version+1,modified=NOW() WHERE id=?');
        $stmt->execute([$note, (int) $user['id'], $id]);
        hub_dev_task_add_thread($id, 'Task notes updated.', null, $user, true);
      } else {
        hub_dev_task_add_thread($id, $message, $image, $user);
        $stmt = $pdo->prepare('UPDATE hub_dev_task SET updated_by=?,version=version+1,modified=NOW() WHERE id=?');
        $stmt->execute([(int) $user['id'], $id]);
      }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($image !== null) @unlink(hub_dev_task_image_dir() . '/' . $image);
    throw $e;
  }
  hub_log_user_action(['user'=>$user, 'action_key'=>'dev_task_' . $action, 'action_title'=>'Dev task ' . str_replace('_', ' ', $action), 'table_name'=>'hub_dev_task', 'record_id'=>$id]);
  return $id;
}

function hub_dev_task_date(string $value): string {
  return (new DateTime($value))->format('d M Y, H:i');
}

function hub_dev_task_list(array $filters, int $page, string $sort): array {
  global $pdo;
  $where = [];
  $params = [];
  $status = (string) ($filters['status'] ?? 'active');
  if (isset(hub_dev_task_statuses()[$status])) {
    $where[] = 't.status=?'; $params[] = $status;
  } elseif ($status !== 'all') {
    $where[] = "t.status IN ('open','in_progress','completed','on_hold')";
  }
  if (!empty($filters['priority'])) { $where[]='t.priority=?'; $params[]=(int) $filters['priority']; }
  $assignee = (string) ($filters['assignee'] ?? '');
  if ($assignee === 'unassigned') $where[] = 't.next_action_by IS NULL';
  elseif ($assignee !== '') { $where[]='t.next_action_by=?'; $params[]=(int) $assignee; }
  if (!empty($filters['raised_by'])) { $where[]='t.created_by=?'; $params[]=(int) $filters['raised_by']; }
  $search = trim((string) ($filters['q'] ?? ''));
  if ($search !== '') {
    $where[] = '(t.task_name LIKE ? OR t.task_note LIKE ? OR EXISTS (SELECT 1 FROM hub_dev_task_comment m WHERE m.task_id=t.id AND m.message LIKE ?))';
    array_push($params, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
  }
  $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM hub_dev_task t' . $whereSql);
  $stmt->execute($params);
  $total = (int) $stmt->fetchColumn();
  $pages = max(1, (int) ceil($total / 50));
  $page = max(1, min($page, $pages));
  $sorts = ['priority'=>'t.priority ASC,t.modified DESC,t.id DESC', 'updated'=>'t.modified DESC,t.id DESC', 'oldest'=>'t.created ASC,t.id ASC', 'name'=>'t.task_name ASC,t.id ASC', 'assignee'=>'COALESCE(a.display_name,a.email,\'zzzz\') ASC,t.priority ASC,t.id DESC'];
  $stmt = $pdo->prepare('SELECT t.*, c.display_name AS creator_name,c.email AS creator_email,u.display_name AS editor_name,u.email AS editor_email,a.display_name AS assignee_name,a.email AS assignee_email,(SELECT COUNT(*) FROM hub_dev_task_comment m WHERE m.task_id=t.id AND m.is_activity=0) AS comment_count FROM hub_dev_task t LEFT JOIN hub_user c ON c.id=t.created_by LEFT JOIN hub_user u ON u.id=t.updated_by LEFT JOIN hub_user a ON a.id=t.next_action_by' . $whereSql . ' ORDER BY ' . ($sorts[$sort] ?? $sorts['priority']) . ' LIMIT 50 OFFSET ' . (($page-1)*50));
  $stmt->execute($params);
  $counts = array_fill_keys(array_keys(hub_dev_task_statuses()), 0);
  foreach ($pdo->query('SELECT status,COUNT(*) AS qty FROM hub_dev_task GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['status']] = (int) $row['qty'];
  return ['rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total, 'page'=>$page, 'pages'=>$pages, 'counts'=>$counts];
}

function hub_dev_task_person(array $task, string $prefix): string {
  return trim((string) ($task[$prefix . '_name'] ?? '')) ?: (trim((string) ($task[$prefix . '_email'] ?? '')) ?: ($prefix === 'assignee' ? 'Unassigned' : 'Unknown user'));
}
