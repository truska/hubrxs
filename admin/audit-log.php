<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
if (!hub_role_at_least('super_admin') && !hub_is_developer()) {
  http_response_code(403);
  echo 'Access denied.';
  exit;
}
$auditActive = hub_is_developer() ? 'dev' : 'super';
$auditSectionLabel = $auditActive === 'dev' ? 'Developer' : 'Super';

global $pdo, $DB_OK;

$messages = hub_flash_messages();
$filters = [
  'q' => trim((string) ($_GET['q'] ?? '')),
  'action' => trim((string) ($_GET['action'] ?? '')),
  'table' => trim((string) ($_GET['table'] ?? '')),
  'user' => trim((string) ($_GET['user'] ?? '')),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$rows = [];
$totalRows = 0;
$auditClock = [];

if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_user_action_log')) {
  try {
    $auditClock = $pdo->query(
      "SELECT NOW() AS server_now,
              DATE_FORMAT(NOW(), '%d %b %Y %H:%i:%s') AS server_now_display,
              @@session.time_zone AS session_time_zone,
              @@system_time_zone AS system_time_zone"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
  } catch (PDOException $e) {
    $auditClock = [];
  }
  $where = ['l.archived = 0'];
  $params = [];

  if ($filters['q'] !== '') {
    $where[] = '(l.action_title LIKE :q OR l.action_key LIKE :q OR l.record_id LIKE :q OR l.sql_text LIKE :q OR l.details_json LIKE :q)';
    $params[':q'] = '%' . $filters['q'] . '%';
  }
  if ($filters['action'] !== '') {
    $where[] = 'l.action_key LIKE :action';
    $params[':action'] = '%' . $filters['action'] . '%';
  }
  if ($filters['table'] !== '') {
    $where[] = 'l.table_name LIKE :table_name';
    $params[':table_name'] = '%' . $filters['table'] . '%';
  }
  if ($filters['user'] !== '') {
    $where[] = '(l.user_email LIKE :user OR u.email LIKE :user OR u.display_name LIKE :user)';
    $params[':user'] = '%' . $filters['user'] . '%';
  }

  $whereSql = implode(' AND ', $where);
  $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM hub_user_action_log l LEFT JOIN hub_user u ON u.id = l.user_id WHERE ' . $whereSql);
  $stmtCount->execute($params);
  $totalRows = (int) $stmtCount->fetchColumn();

  $stmt = $pdo->prepare(
    'SELECT l.*, u.display_name, u.email AS joined_email
     FROM hub_user_action_log l
     LEFT JOIN hub_user u ON u.id = l.user_id
     WHERE ' . $whereSql . '
     ORDER BY l.action_time DESC, l.id DESC
     LIMIT ' . $perPage . ' OFFSET ' . $offset
  );
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hub_audit_log_user_label(array $row): string {
  $name = trim((string) ($row['display_name'] ?? ''));
  $email = trim((string) (($row['joined_email'] ?? '') ?: ($row['user_email'] ?? '')));
  if ($name !== '' && $email !== '') {
    return $name . ' (' . $email . ')';
  }
  if ($name !== '') {
    return $name;
  }
  return $email !== '' ? $email : 'System';
}

function hub_audit_log_details(?string $json): string {
  $json = trim((string) $json);
  if ($json === '') {
    return '';
  }
  $decoded = json_decode($json, true);
  if (!is_array($decoded)) {
    return $json;
  }
  $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  return $pretty !== false ? $pretty : $json;
}

function hub_audit_log_outcome(array $row): string {
  $decoded = json_decode((string) ($row['details_json'] ?? ''), true);
  $outcome = is_array($decoded) ? strtolower((string) ($decoded['outcome'] ?? '')) : '';
  if (in_array($outcome, ['success', 'fail'], true)) {
    return $outcome;
  }
  if (($row['action_key'] ?? '') === 'login') {
    return 'success';
  }
  if (in_array(($row['action_key'] ?? ''), ['login_failed', 'magic_link_rejected'], true)) {
    return 'fail';
  }
  return '';
}

function hub_audit_log_url(array $params): string {
  $current = [
    'q' => $_GET['q'] ?? '',
    'action' => $_GET['action'] ?? '',
    'table' => $_GET['table'] ?? '',
    'user' => $_GET['user'] ?? '',
    'page' => $_GET['page'] ?? 1,
  ];
  return '/admin/audit-log.php?' . http_build_query(array_merge($current, $params));
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Audit Log</title>
  <link rel="stylesheet" href="/css/hub.css?v=20260807-login-outcomes">
</head>
<body class="admin-page audit-log-page">
  <?php echo hub_admin_header($auditActive); ?>
  <div class="stack audit-log-content">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand"><?php echo hub_h($auditSectionLabel); ?></p>
            <h1>Audit Log</h1>
            <p class="muted">Review recorded logins, account changes, data changes, and imports.</p>
          </div>
          <div class="links">
            <?php if (hub_role_at_least('super_admin')): ?><a href="/super.php">Super</a><?php endif; ?>
            <?php if (hub_is_developer()): ?><a href="/developer.php">Dev</a><?php endif; ?>
            <a href="/dashboard.php">Dashboard</a>
          </div>
        </div>
        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <div class="audit-log-clock-row">
          <p class="muted">Showing the most recent audit entries.</p>
          <div class="audit-log-clocks muted" aria-label="Audit log clocks">
            <?php if (!empty($auditClock['server_now'])): ?>
              <span>Server: <?php echo hub_h((string) ($auditClock['server_now_display'] ?? $auditClock['server_now'])); ?> <?php echo hub_h((string) ((($auditClock['session_time_zone'] ?? '') === 'SYSTEM' && !empty($auditClock['system_time_zone'])) ? $auditClock['system_time_zone'] : ($auditClock['session_time_zone'] ?? ''))); ?></span>
            <?php endif; ?>
            <span>Local: <span id="audit-local-time">Detecting…</span></span>
          </div>
        </div>

        <?php if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user_action_log')): ?>
          <div class="alert error">Audit log table is not available.</div>
        <?php elseif (empty($rows)): ?>
          <div class="alert info">No audit log entries found.</div>
        <?php else: ?>
          <div class="import-data-table-wrap">
            <table class="table import-data-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Time</th>
                  <th>User</th>
                  <th>Action</th>
                  <th>Record</th>
                  <th>IP</th>
                  <th>Details</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <?php
                    $details = hub_audit_log_details($row['details_json'] ?? null);
                    $outcome = hub_audit_log_outcome($row);
                    $hasExpanded = ($details !== '') || !empty($row['sql_text']);
                  ?>
                  <tr class="audit-log-main-row<?php echo $hasExpanded ? ' has-expanded-content' : ''; ?>">
                    <td><?php echo (int) $row['id']; ?></td>
                    <td class="muted"><?php echo hub_h((string) $row['action_time']); ?></td>
                    <td><?php echo hub_h(hub_audit_log_user_label($row)); ?></td>
                    <td>
                      <strong><?php echo hub_h((string) $row['action_title']); ?></strong>
                      <div class="muted"><?php echo hub_h((string) $row['action_key']); ?></div>
                      <?php if ($outcome !== ''): ?>
                        <div class="audit-login-outcome <?php echo hub_h($outcome); ?>"><?php echo $outcome === 'success' ? 'Success' : 'Fail'; ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php echo hub_h((string) ($row['table_name'] ?? '')); ?>
                      <?php if (!empty($row['record_id'])): ?>
                        <div class="muted">#<?php echo hub_h((string) $row['record_id']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="muted"><?php echo hub_h((string) ($row['ip_address'] ?? '')); ?></td>
                    <td>
                      <?php if ($hasExpanded): ?>
                        <button type="button" class="audit-log-toggle" aria-expanded="false">
                          <span class="audit-log-toggle-icon" aria-hidden="true">&#9662;</span>
                          <span>Details</span>
                        </button>
                      <?php else: ?>
                        <span class="muted">-</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php if ($hasExpanded): ?>
                    <tr class="audit-log-expanded-row" hidden>
                      <td colspan="7">
                        <div class="audit-log-expanded-panel">
                          <?php if ($details !== ''): ?>
                            <section>
                              <h3>Details</h3>
                              <pre><?php echo hub_h($details); ?></pre>
                            </section>
                          <?php endif; ?>
                          <?php if (!empty($row['sql_text'])): ?>
                            <section>
                              <h3>SQL written</h3>
                              <pre><?php echo hub_h((string) $row['sql_text']); ?></pre>
                            </section>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <nav class="import-data-pagination" aria-label="Audit log pages">
            <div class="links">
              <?php if ($page > 1): ?>
                <a href="<?php echo hub_h(hub_audit_log_url(['page' => $page - 1])); ?>">Previous</a>
              <?php endif; ?>
              <span class="muted">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></span>
              <?php if ($page < $totalPages): ?>
                <a href="<?php echo hub_h(hub_audit_log_url(['page' => $page + 1])); ?>">Next</a>
              <?php endif; ?>
            </div>
          </nav>
        <?php endif; ?>
      </div>
  </div>
  <script>
    (function () {
      var localClock = document.getElementById('audit-local-time');
      if (localClock) {
        var updateLocalClock = function () {
          var now = new Date();
          localClock.textContent = now.toLocaleString('en-GB', {
            year: 'numeric', month: 'short', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            timeZoneName: 'short', hourCycle: 'h23'
          }).replace(',', '');
        };
        updateLocalClock();
        window.setInterval(updateLocalClock, 1000);
      }
      document.addEventListener('click', function (event) {
        var toggle = event.target.closest('.audit-log-toggle');
        if (!toggle) return;
        var row = toggle.closest('tr');
        var expanded = row ? row.nextElementSibling : null;
        if (!expanded || !expanded.classList.contains('audit-log-expanded-row')) return;
        var isOpen = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        toggle.querySelector('.audit-log-toggle-icon').innerHTML = isOpen ? '&#9662;' : '&#9652;';
        expanded.hidden = isOpen;
        row.classList.toggle('is-open', !isOpen);
      });
    })();
  </script>
</body>
</html>
