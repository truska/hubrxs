<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}
require_once __DIR__ . '/../includes/app/company_details.php';
if (!$DB_OK || !($pdo instanceof PDO) || !($table = cms_preferences_table())) exit("Company preferences unavailable.\n");
$definitions = [];
foreach (hub_company_fields() as $group => $fields) foreach ($fields as $name => $field) $definitions[$name] = [$group, $field];
$names = array_merge(array_keys($definitions), ['prefUSAAddress', 'prefEuropeAddress']);
$placeholders = implode(',', array_fill(0, count($names), '?'));
$find = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE name IN (' . $placeholders . ')');
$find->execute($names);
$rows = $find->fetchAll(PDO::FETCH_ASSOC);
$existing = [];
foreach ($rows as $row) $existing[$row['name']] = $row;
// Initial conversion only: later installer runs preserve all saved preferences.
$initial = !isset($existing['prefUSAAddress']) && !isset($existing['prefUSAAddress1']);
if ($initial) {
  $backup = __DIR__ . '/../../private/company-preferences-' . date('Ymd-His') . '.json';
  if (file_put_contents($backup, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) throw new RuntimeException('Could not back up company preferences.');
  chmod($backup, 0600);
}
// Split the legacy address lines using their established USA/Europe formats.
foreach (['USA' => [0, 1, 2, 3, 4, 5], 'Europe' => [0, 1, 2, null, 3, null]] as $office => $positions) {
  $legacy = $existing['pref' . $office . 'Address'] ?? null;
  if (!$legacy) continue;
  $lines = preg_split('/\r\n|\r|\n/', trim((string) $legacy['value']));
  foreach (array_combine(['Address1', 'Address2', 'Town', 'County', 'Country', 'Postcode'], $positions) as $field => $position) {
    $definitions['pref' . $office . $field][1][2] = $position === null ? '' : trim((string) ($lines[$position] ?? ''));
  }
}
$pdo->beginTransaction();
try {
  $insert = $pdo->prepare('INSERT INTO `' . $table . '` (name,label,value,migrule,notes,prefCat,field,class,userlevel,sort,comment,placeholder,required,max,min,step,tooltip,showoncms,showonweb,allowedit,archived) VALUES (:name,:label,:value,\'copy\',\'\',:category,1,\'medium\',30,:sort,\'\',\'\',\'No\',2048,0,1,\'\',\'Yes\',\'Yes\',\'Yes\',0)');
  $update = $pdo->prepare('UPDATE `' . $table . '` SET value=:value, label=:label, showoncms=\'Yes\', showonweb=\'Yes\', archived=0 WHERE id=:id');
  $sort = 1000;
  foreach ($definitions as $name => [$group, [$label, $type, $default]]) {
    $sort += 10;
    if (isset($existing[$name])) {
      if ($initial) $update->execute([':value'=>$default, ':label'=>$group . ' — ' . $label, ':id'=>$existing[$name]['id']]);
    } else {
      $insert->execute([':name'=>$name, ':label'=>$group . ' — ' . $label, ':value'=>$default, ':category'=>$group === 'Social links' ? 3 : 1, ':sort'=>$sort]);
    }
  }
  // Retain old values for reference while removing the redundant settings from CMS.
  $pdo->exec("UPDATE `" . $table . "` SET archived=1, showoncms='No', showonweb='No' WHERE name IN ('prefUSAAddress', 'prefEuropeAddress')");
  $pdo->commit();
  echo "Company details installed; existing preference IDs reused.\n";
} catch (Throwable $e) {
  $pdo->rollBack();
  throw $e;
}
