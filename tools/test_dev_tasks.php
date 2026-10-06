<?php
// CLI integration checks use connection-local temporary tables only.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../includes/app/dev_tasks.php';
require_once __DIR__ . '/../includes/app/admin_tools.php';
if (!$DB_OK || !($pdo instanceof PDO)) throw new RuntimeException('A database connection is required.');
function dt_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
  echo 'PASS: ' . $message . "\n";
}
function dt_clone(string $name): void {
  global $pdo;
  $create=$pdo->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM)[1];
  $create=preg_replace('/^\s*CONSTRAINT[^\n]+\n/m', '', $create);
  $create=preg_replace('/,\n\)/', "\n)", $create);
  $pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $create));
}
foreach (['hub_dev_task','hub_dev_task_comment','hub_user','hub_user_action_log'] as $table) dt_clone($table);
$pdo->exec("INSERT INTO hub_user(id,email,password_hash,display_name,role,login_enabled,archived) VALUES (900001,'dev1@example.com','unused','Developer One','developer',1,0),(900002,'dev2@example.com','unused','Developer Two','developer',1,0),(900003,'super@example.com','unused','Super Admin','super_admin',1,0),(900004,'disabled@example.com','unused','Disabled Dev','developer',0,0)");
$developer=['id'=>900001,'email'=>'dev1@example.com','display_name'=>'Developer One','role'=>'developer'];
$second=['id'=>900002,'email'=>'dev2@example.com','display_name'=>'Developer Two','role'=>'developer'];
$_SESSION[HUB_SESSION_KEY]=$developer;
$base=['task_name'=>'<script>Task fixture</script>', 'priority'=>'2', 'status'=>'open', 'next_action_by'=>'900002', 'task_note'=>'Persistent plan', 'message'=>'Initial question'];
$id=hub_dev_task_save('create',0,$base,[],$developer);
$task=hub_dev_task_get($id);
dt_check($task['task_note']==='Persistent plan' && (int)$task['created_by']===900001 && (int)$task['next_action_by']===900002,'Task, first comment, notes, creator and allocation created');
hub_dev_task_save('comment',$id,['message'=>'Progress comment'],[],$second);
$task=hub_dev_task_get($id);
dt_check((int)$task['updated_by']===900002 && $task['task_note']==='Persistent plan','Comments update editor without overwriting notes');
$staleRejected=false;
try { hub_dev_task_save('save_notes',$id,['task_note'=>'Stale overwrite','version'=>1],[],$developer); } catch(RuntimeException $e) { $staleRejected=str_contains($e->getMessage(),'changed'); }
dt_check($staleRejected && hub_dev_task_get($id)['task_note']==='Persistent plan','Concurrent stale note save is rejected');
hub_dev_task_save('save_notes',$id,['task_note'=>'Updated shared plan','version'=>$task['version']],[],$developer);
foreach (array_keys(hub_dev_task_statuses()) as $status) {
  $task=hub_dev_task_get($id);
  hub_dev_task_save('save_task',$id,array_merge($base,['status'=>$status,'version'=>$task['version']]),[],$developer);
  dt_check(hub_dev_task_get($id)['status']===$status,'Status transition: ' . $status);
}
foreach ([['priority'=>'6'],['status'=>'invalid'],['next_action_by'=>'900003'],['next_action_by'=>'900004'],['task_name'=>'']] as $bad) {
  $rejected=false; try { hub_dev_task_validate(array_merge($base,$bad)); } catch(RuntimeException $e) { $rejected=true; }
  dt_check($rejected,'Invalid task field rejected: ' . array_key_first($bad));
}
foreach (['user','manager','admin','super_admin'] as $role) {
  $denied=false; try { hub_dev_task_save('comment',$id,['message'=>'Forbidden'],[],['id'=>900003,'role'=>$role]); } catch(RuntimeException $e) { $denied=true; }
  dt_check($denied,'Direct write rejected for ' . $role);
  dt_check(!in_array('dev_tasks',array_column(hub_admin_tools_for_panel('developer',['role'=>$role]),'key'),true),'Tools menu hides Dev Tasks from ' . $role);
}
dt_check(in_array('dev_tasks',array_column(hub_admin_tools_for_panel('developer',$developer),'key'),true),'Developer Tools menu includes Dev Tasks');
$before=(int)$pdo->query('SELECT COUNT(*) FROM hub_dev_task_comment')->fetchColumn();
$rejected=false; try { hub_dev_task_save('comment',$id,['message'=>''],[],$developer); } catch(RuntimeException $e) { $rejected=true; }
dt_check($rejected && !$pdo->inTransaction() && (int)$pdo->query('SELECT COUNT(*) FROM hub_dev_task_comment')->fetchColumn()===$before,'Empty comment rolls back without changing thread');
$rejected=false; try { hub_dev_task_save('comment',99999999,['message'=>'Missing task'],[],$developer); } catch(RuntimeException $e) { $rejected=true; }
dt_check($rejected && !$pdo->inTransaction(),'Missing task cannot receive orphan comments');
$thread=$pdo->query('SELECT message FROM hub_dev_task_comment WHERE task_id=' . $id . ' ORDER BY created,id')->fetchAll(PDO::FETCH_COLUMN);
dt_check($thread[0]==='Initial question' && $thread[1]==='Progress comment' && in_array('Task notes updated.',$thread,true),'Thread is chronological and records task activity');
$list=hub_dev_task_list(['status'=>'all','col'=>['conversation'=>'Progress comment','assignee'=>'900002']],1,'priority');
dt_check($list['total']===1 && (int)$list['rows'][0]['comment_count']===2,'Search finds comment text and counts conversation comments');
dt_check(hub_dev_task_list(['status'=>'open'],1,'updated')['total']===0,'Status filter excludes On Hold task');
dt_check(hub_dev_task_list(['status'=>'active'],1,'updated')['total']===1,'Active filter includes On Hold task');
$other=hub_dev_task_save('create',0,['task_name'=>'Another fixture','priority'=>'5','status'=>'future','message'=>'Different conversation','task_note'=>'','next_action_by'=>''],[],$second);
$pdo->exec("UPDATE hub_dev_task SET modified='2026-01-01 10:00:00' WHERE id=" . $id);
$pdo->exec("UPDATE hub_dev_task SET modified='2026-01-02 10:00:00' WHERE id=" . $other);
foreach (['id'=>'#'.$id,'priority'=>'2','name'=>'Task fixture','assignee'=>'900002','raised_by'=>'900001','updated_by'=>'900001','conversation'=>'Progress comment','updated'=>'01 Jan 2026','status'=>'on_hold'] as $key=>$value) {
  $result=hub_dev_task_list(['status'=>'all','col'=>[$key=>$value]],1,'id_asc');
  dt_check($result['total']===1 && (int)$result['rows'][0]['id']===$id,'Column filter: ' . $key);
}
dt_check(hub_dev_task_list(['status'=>'all','col'=>['assignee'=>'unassigned']],1,'id_asc')['total']===1,'Unassigned select filter');
foreach (['id','priority','name','assignee','raised_by','updated_by','conversation','updated','status'] as $key) foreach (['asc','desc'] as $direction) {
  $result=hub_dev_task_list(['status'=>'all'],1,$key.'_'.$direction);
  dt_check(count($result['rows'])===2,'Header sort executes: ' . $key . ' ' . $direction);
}
dt_check((int)hub_dev_task_list(['status'=>'all'],1,'priority_asc')['rows'][0]['id']===$id && (int)hub_dev_task_list(['status'=>'all'],1,'priority_desc')['rows'][0]['id']===$other,'Priority sorting toggles direction');
dt_check((int)hub_dev_task_list(['status'=>'all'],1,'name_asc')['rows'][0]['id']===$id && (int)hub_dev_task_list(['status'=>'all'],1,'name_desc')['rows'][0]['id']===$other,'Task-name sorting toggles direction');
dt_check((int)hub_dev_task_list(['status'=>'all'],1,'updated_asc')['rows'][0]['id']===$id && (int)hub_dev_task_list(['status'=>'all'],1,'updated_desc')['rows'][0]['id']===$other,'Updated sorting toggles direction');
dt_check(hub_dev_task_list(['status'=>'future'],1,'id_asc')['total']===1 && hub_dev_task_list(['status'=>'all'],1,'id_asc')['counts']['future']===1,'Status links filter tasks and show full-list counts');
for ($i=0;$i<52;$i++) $pdo->prepare('INSERT INTO hub_dev_task(task_name,task_note,created_by,updated_by) VALUES (?,\'\',900001,900001)')->execute(['Paging fixture ' . $i]);
$list=hub_dev_task_list(['status'=>'all'],2,'updated');
dt_check($list['total']===54 && count($list['rows'])===4 && $list['pages']===2,'Task list paginates without dropping records');
$_SERVER['REQUEST_METHOD']='GET'; $_GET=['id'=>$id];
ob_start(); include __DIR__ . '/../admin/dev-task.php'; $html=ob_get_clean();
dt_check(str_contains($html,'&lt;script&gt;Task fixture&lt;/script&gt;') && !str_contains($html,'<script>Task fixture</script>'),'Task names are escaped in page output');
dt_check(str_contains($html,'Updated shared plan') && str_contains($html,'Progress comment'),'Detail page renders notes and comments');
$_GET=[];
ob_start(); include __DIR__ . '/../admin/dev-tasks.php'; $html=ob_get_clean();
dt_check(str_contains($html,'Dev Tasks') && str_contains($html,'Next action') && str_contains($html,'Last edited by'),'List page renders task tracking fields');
dt_check(substr_count($html, 'scope="col"')===9 && substr_count($html, 'data-auto-filter')===9 && str_contains($html,'import-data-filter-row'),'All nine table headers provide sorting and search/select filters');
dt_check(!str_contains($html,'class="dev-task-filters"') && !str_contains($html,'Sort by') && str_contains($html,'dev-task-status-tabs'),'Status counts replace standalone filters');
dt_check(str_contains($html,'col%5Bconversation%5D') && str_contains($html,'sort=id_asc'),'Sort links preserve column-filter parameters');
echo "All checks passed using temporary tables; live task/user/audit data unchanged.\n";
