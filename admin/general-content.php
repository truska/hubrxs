<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/general_content.php';
hub_require_super_admin(); global $pdo, $DB_OK;
$error=null; $messages=hub_flash_messages();
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hub_verify_csrf($_POST['csrf']??'')) $error='Session expired. Please try again.';
  elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_general_content_ready()) $error='General content storage is not available. Run the schema installer.';
  else try {
    $id=(int)($_POST['id']??0);
    $data=[':id'=>$id,':key'=>preg_replace('/[^a-z0-9_-]+/i','-',strtolower(trim((string)($_POST['content_key']??'')))),':title'=>trim((string)($_POST['title']??'')),':body'=>trim((string)($_POST['body']??'')),':sort'=>(int)($_POST['sort']??100),':published'=>!empty($_POST['published'])?1:0];
    if ($id <= 0) {
      $insertData=$data; unset($insertData[':id']);
      $pdo->prepare('INSERT INTO hub_general_content (content_key,title,body,sort,published,archived,created,modified) VALUES (:key,:title,:body,:sort,:published,0,NOW(),NOW())')->execute($insertData);
      $id=(int)$pdo->lastInsertId();
    } else {
      $pdo->prepare('UPDATE hub_general_content SET content_key=:key,title=:title,body=:body,sort=:sort,published=:published,modified=NOW() WHERE id=:id LIMIT 1')->execute($data);
    }
    hub_flash('success','General content saved.'); hub_redirect('/admin/general-content.php?id='.$id);
  } catch(Throwable $e) {$error='Unable to save content.';}
}
$blocks=hub_general_content_all(); $selectedId=(int)($_GET['id']??($blocks[0]['id']??0)); $selected=null;
foreach($blocks as $block) if((int)$block['id']===$selectedId){$selected=$block;break;} if(!$selected&&$blocks)$selected=$blocks[0];
echo '<script src="/js/tinymce/tinymce.min.js"></script><script src="/js/general-content-editor.js"></script>';
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>General Content</title><link rel="stylesheet" href="/css/hub.css"></head><body class="admin-page"><?php echo hub_admin_header('super');?><div class="stack"><div class="wrap"><div class="card"><p class="brand">Content</p><h1>General Content</h1><p class="muted">Reusable dashboard and site sections, in display order.</p><?php foreach($messages as $m):?><div class="alert <?php echo hub_h($m['type']);?>"><?php echo hub_h($m['message']);?></div><?php endforeach;?><?php if($error):?><div class="alert error"><?php echo hub_h($error);?></div><?php endif;?></div>
<?php if($selected):?><div class="card"><form method="get" action="/admin/general-content.php" class="content-page-selector"><div><label for="content-id">Content item</label><select id="content-id" name="id" onchange="this.form.submit()"><?php foreach($blocks as $block):?><option value="<?php echo (int)$block['id'];?>" <?php echo (int)$block['id']===(int)$selected['id']?'selected':'';?>><?php echo hub_h($block['title']);?></option><?php endforeach;?></select></div></form></div><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token());?>"><input type="hidden" name="id" value="<?php echo (int)$selected['id'];?>"><div class="form-grid"><div><label>Content key</label><input name="content_key" value="<?php echo hub_h($selected['content_key']);?>" required></div><div><label>Title</label><input name="title" value="<?php echo hub_h($selected['title']);?>" required></div><div><label>Sort</label><input type="number" name="sort" value="<?php echo (int)$selected['sort'];?>"></div></div><label>Content</label><textarea name="body" rows="8"><?php echo hub_h($selected['body']);?></textarea><p><label><input type="checkbox" name="published" value="1" <?php echo !empty($selected['published'])?'checked':'';?>> Published</label></p><button class="but1">Save</button></form></div><?php else:?><div class="alert info">No general content is available yet.</div><?php endif;?></div></div></body></html>
