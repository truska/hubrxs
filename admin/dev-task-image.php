<?php
require_once __DIR__ . '/../includes/app/dev_tasks.php';
hub_require_dev_tasks();
if (!hub_dev_task_ready()) { http_response_code(404); exit('Screenshot not found.'); }
$stmt=$pdo->prepare('SELECT c.image_filename,c.task_id FROM hub_dev_task_comment c INNER JOIN hub_dev_task t ON t.id=c.task_id WHERE c.id=?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$image=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$image || !preg_match('/^[a-f0-9]{48}\.png$/', (string) $image['image_filename'])) { http_response_code(404); exit('Screenshot not found.'); }
$path=hub_dev_task_image_dir() . '/' . $image['image_filename'];
if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Screenshot not found.'); }
session_write_close();
header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: inline; filename="task-' . (int) $image['task_id'] . '-screenshot.png"');
header('Content-Length: ' . filesize($path));
readfile($path);
