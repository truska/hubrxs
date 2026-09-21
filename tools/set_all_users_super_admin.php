<?php
// Temporary one-time role migration. Run from the server CLI only.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../includes/app/bootstrap.php';
if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user')) { fwrite(STDERR, "hub_user is not available.\n"); exit(1); }
$stmt = $pdo->prepare("UPDATE hub_user SET role = 'super_admin', modified = NOW() WHERE archived = 0 AND role <> 'super_admin'");
$stmt->execute();
echo $stmt->rowCount() . " active user(s) set to super_admin.\n";
