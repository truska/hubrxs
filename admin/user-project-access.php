<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_manager();

global $pdo, $DB_OK;

$currentUser = hub_current_user();
$canManageAllUsers = hub_can_manage_all_users($currentUser);
$currentUserRoleRank = hub_role_rank(hub_role_key($currentUser));
$scopeCustomerId = $canManageAllUsers ? null : hub_effective_customer_id($currentUser);
$headerActive = $canManageAllUsers ? 'admin' : 'manager';
$backUrl = '/admin/users.php';
$error = null;
$messages = hub_flash_messages();
$userId = (int) ($_GET['user_id'] ?? ($_POST['user_id'] ?? 0));
$targetUser = null;
$customer = null;
$projects = [];
$projectAccess = [];
$search = trim((string) ($_GET['q'] ?? ''));

function hub_user_project_access_customer_label(array $row): string {
  $name = trim((string) ($row['customer_name'] ?? ''));
  $code = trim((string) ($row['customer_code'] ?? ''));
  if ($name !== '' && $code !== '') {
    return $name . ' (' . $code . ')';
  }
  return $name !== '' ? $name : $code;
}

function hub_user_project_access_project_label(array $row): string {
  $code = trim((string) ($row['code'] ?? ''));
  $name = trim((string) ($row['name'] ?? ''));
  if ($code !== '' && $name !== '' && strcasecmp($code, $name) !== 0) {
    return $code . ' - ' . $name;
  }
  return $code !== '' ? $code : ($name !== '' ? $name : ('Project #' . (int) ($row['id'] ?? 0)));
}

if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user') || !hub_table_exists('hub_project')) {
  $error = 'Database not available.';
} elseif ($userId <= 0) {
  $error = 'User not found.';
} else {
  $stmtUser = $pdo->prepare(
    'SELECT u.id, u.email, u.display_name, u.role, u.customer_id, c.name AS customer_name, c.code AS customer_code
     FROM hub_user u
     LEFT JOIN hub_customer c ON c.id = u.customer_id
     WHERE u.id = :id
       AND u.archived = 0
     LIMIT 1'
  );
  $stmtUser->execute([':id' => $userId]);
  $targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: null;

  if (!$targetUser) {
    $error = 'User not found.';
  } elseif (hub_role_rank((string) ($targetUser['role'] ?? 'user')) > $currentUserRoleRank) {
    $error = 'You cannot manage access for a user above your own access level.';
    $targetUser = null;
  } elseif (!$canManageAllUsers && (int) ($targetUser['customer_id'] ?? 0) !== (int) $scopeCustomerId) {
    $error = 'User not found for your company.';
    $targetUser = null;
  }
}

if ($targetUser) {
  $targetCustomerId = (int) ($targetUser['customer_id'] ?? 0);
  if ($targetCustomerId <= 0) {
    $error = 'This user is not assigned to a customer, so project access cannot be managed.';
  } else {
    $customer = [
      'id' => $targetCustomerId,
      'label' => hub_user_project_access_customer_label($targetUser),
    ];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_project_access') {
      if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please try again.';
      } elseif ((string) ($targetUser['role'] ?? 'user') !== 'user') {
        $stmtDelete = $pdo->prepare('DELETE FROM hub_user_project_access WHERE user_id = :user_id');
        $stmtDelete->execute([':user_id' => $userId]);
        hub_flash('success', 'Project restrictions cleared. Project access only applies to User role accounts.');
        hub_redirect('/admin/user-project-access.php?user_id=' . $userId);
      } elseif (!hub_table_exists('hub_user_project_access')) {
        $error = 'Project access table is not available.';
      } else {
        $stmtProjectIds = $pdo->prepare('SELECT id FROM hub_project WHERE customer_id = :customer_id AND archived = 0 ORDER BY code ASC, id ASC');
        $stmtProjectIds->execute([':customer_id' => $targetCustomerId]);
        $validProjectIds = array_map('intval', $stmtProjectIds->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $postedAllowed = $_POST['project_access'] ?? [];
        $allowed = [];
        if (is_array($postedAllowed)) {
          foreach ($postedAllowed as $projectId) {
            $allowed[(int) $projectId] = true;
          }
        }

        $allAllowed = true;
        foreach ($validProjectIds as $projectId) {
          if (empty($allowed[$projectId])) {
            $allAllowed = false;
            break;
          }
        }

        $pdo->beginTransaction();
        try {
          $stmtDelete = $pdo->prepare('DELETE FROM hub_user_project_access WHERE user_id = :user_id');
          $stmtDelete->execute([':user_id' => $userId]);
          if (!$allAllowed) {
            $stmtInsert = $pdo->prepare(
              'INSERT INTO hub_user_project_access
                (user_id, project_id, can_access, created, modified)
               VALUES
                (:user_id, :project_id, :can_access, NOW(), NOW())'
            );
            foreach ($validProjectIds as $projectId) {
              $stmtInsert->execute([
                ':user_id' => $userId,
                ':project_id' => $projectId,
                ':can_access' => !empty($allowed[$projectId]) ? 1 : 0,
              ]);
            }
          }
          $pdo->commit();
          hub_flash('success', $allAllowed ? 'Project access reset to all customer projects.' : 'Project access saved.');
          hub_redirect('/admin/user-project-access.php?user_id=' . $userId);
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          $error = 'Unable to save project access.';
        }
      }
    }

    $stmtProjects = $pdo->prepare(
      'SELECT id, code, name, needs_review
       FROM hub_project
       WHERE customer_id = :customer_id
         AND archived = 0
       ORDER BY code ASC, name ASC, id ASC'
    );
    $stmtProjects->execute([':customer_id' => $targetCustomerId]);
    $projects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (hub_table_exists('hub_user_project_access')) {
      $stmtAccess = $pdo->prepare('SELECT project_id, can_access FROM hub_user_project_access WHERE user_id = :user_id');
      $stmtAccess->execute([':user_id' => $userId]);
      foreach ($stmtAccess->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $projectAccess[(int) ($row['project_id'] ?? 0)] = (int) ($row['can_access'] ?? 0);
      }
    }
  }
}

$hasExplicitAccess = !empty($projectAccess);
$filteredProjects = [];
foreach ($projects as $project) {
  if ($search !== '') {
    $haystack = mb_strtolower(hub_user_project_access_project_label($project));
    if (mb_strpos($haystack, mb_strtolower($search)) === false) {
      continue;
    }
  }
  $filteredProjects[] = $project;
}
$allowedCount = 0;
foreach ($projects as $project) {
  $projectId = (int) ($project['id'] ?? 0);
  if (!$hasExplicitAccess || ((int) ($projectAccess[$projectId] ?? 0) === 1)) {
    $allowedCount++;
  }
}
$targetLabel = '';
if ($targetUser) {
  $targetLabel = trim((string) ($targetUser['display_name'] ?? ''));
  if ($targetLabel === '') {
    $targetLabel = trim((string) ($targetUser['email'] ?? ''));
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Project Access</title>
  <link rel="stylesheet" href="/css/hub.css?v=20260622-password-reveal">
</head>
<body class="admin-page user-project-access-page">
  <?php echo hub_admin_header($headerActive); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card user-project-access-header">
        <div class="flex">
          <div>
            <p class="brand">Users</p>
            <h1>Project Access</h1>
            <?php if ($targetUser): ?>
              <p class="muted"><?php echo hub_h($targetLabel); ?><?php echo trim((string) ($targetUser['email'] ?? '')) !== '' ? ' [' . hub_h((string) $targetUser['email']) . ']' : ''; ?></p>
            <?php else: ?>
              <p class="muted">Manage project visibility for customer users.</p>
            <?php endif; ?>
          </div>
          <div class="links">
            <a href="<?php echo hub_h($backUrl); ?>">Back to Users</a>
            <a href="/dashboard.php">Dashboard</a>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endif; ?>
      </div>

      <?php if ($targetUser && !$error): ?>
        <div class="card user-project-access-main">
          <div class="flex">
            <div>
              <p class="brand"><?php echo hub_h((string) ($customer['label'] ?? 'Customer')); ?></p>
              <h2><?php echo $hasExplicitAccess ? 'Selected Projects' : 'All Customer Projects'; ?></h2>
              <p class="muted"><?php echo number_format($allowedCount); ?> of <?php echo number_format(count($projects)); ?> projects currently visible.</p>
            </div>
            <?php if ((string) ($targetUser['role'] ?? 'user') !== 'user'): ?>
              <div class="alert info" style="margin:0;">Project restrictions only apply to User role accounts.</div>
            <?php endif; ?>
          </div>

          <form method="get" action="/admin/user-project-access.php" class="import-data-controls admin-table-controls">
            <input type="hidden" name="user_id" value="<?php echo (int) $userId; ?>">
            <div>
              <label for="q">Search Projects</label>
              <input id="q" name="q" type="search" value="<?php echo hub_h($search); ?>" placeholder="Search">
            </div>
            <div class="links import-data-reset">
              <button type="submit">Search</button>
              <?php if ($search !== ''): ?>
                <a href="/admin/user-project-access.php?user_id=<?php echo (int) $userId; ?>">Clear</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if (empty($projects)): ?>
            <div class="alert info">No projects are assigned to this customer.</div>
          <?php else: ?>
            <form method="post" action="/admin/user-project-access.php" class="project-access-form">
              <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
              <input type="hidden" name="action" value="save_project_access">
              <input type="hidden" name="user_id" value="<?php echo (int) $userId; ?>">
              <?php foreach ($projects as $project): ?>
                <?php
                  $projectId = (int) ($project['id'] ?? 0);
                  $isVisible = false;
                  foreach ($filteredProjects as $visibleProject) {
                    if ((int) ($visibleProject['id'] ?? 0) === $projectId) {
                      $isVisible = true;
                      break;
                    }
                  }
                  $checked = !$hasExplicitAccess || ((int) ($projectAccess[$projectId] ?? 0) === 1);
                ?>
                <?php if (!$isVisible && $checked): ?>
                  <input type="hidden" name="project_access[]" value="<?php echo $projectId; ?>">
                <?php endif; ?>
              <?php endforeach; ?>

              <div class="links project-access-actions project-access-actions-top">
                <button type="button" data-project-access-select="all">Select All</button>
                <button type="button" data-project-access-select="none">Clear All</button>
              </div>

              <div class="import-data-table-wrap">
                <table class="table import-data-table project-access-table">
                  <thead>
                    <tr>
                      <th>Access</th>
                      <th>Project</th>
                      <th>Name</th>
                      <th>ID</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($filteredProjects)): ?>
                      <tr><td colspan="5" class="muted">No projects match this search.</td></tr>
                    <?php else: ?>
                      <?php foreach ($filteredProjects as $project): ?>
                        <?php
                          $projectId = (int) ($project['id'] ?? 0);
                          $checked = !$hasExplicitAccess || ((int) ($projectAccess[$projectId] ?? 0) === 1);
                        ?>
                        <tr>
                          <td class="project-access-check">
                            <input type="checkbox" name="project_access[]" value="<?php echo $projectId; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                          </td>
                          <td><?php echo hub_h((string) ($project['code'] ?? '')); ?></td>
                          <td><?php echo hub_h((string) ($project['name'] ?? '')); ?></td>
                          <td><?php echo $projectId; ?></td>
                          <td><?php echo ((int) ($project['needs_review'] ?? 0) === 1) ? 'Review' : 'Active'; ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <div class="links project-access-save-actions">
                <button type="submit" class="main-action">Save Project Access</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
    document.querySelectorAll('[data-project-access-select]').forEach(function (button) {
      button.addEventListener('click', function () {
        var checked = button.getAttribute('data-project-access-select') === 'all';
        document.querySelectorAll('.project-access-table input[type="checkbox"]').forEach(function (box) {
          box.checked = checked;
        });
      });
    });
  </script>
</body>
</html>
