<?php
require_once __DIR__ . '/../includes/app/auth.php';
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/import_sales_orders.php'; // for hub_table_exists etc.
require_once __DIR__ . '/../includes/app/images.php';
hub_require_manager();

$currentUser = hub_current_user();
$canManageAllUsers = hub_can_manage_all_users($currentUser);
$scopeCustomerId = $canManageAllUsers ? null : hub_effective_customer_id($currentUser);
$isCompanyManager = !$canManageAllUsers;
$headerActive = $canManageAllUsers ? 'admin' : 'manager';
$sectionLabel = $canManageAllUsers ? 'Admin' : 'Manager';
$backUrl = $canManageAllUsers ? '/admin.php' : '/manager.php';
$backLabel = $canManageAllUsers ? 'Back to Admin' : 'Back to Manager';

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();
$temporaryPasswordResult = null;

// Fetch customers for dropdown.
$customerOptions = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
  $stmtCust = $pdo->query('SELECT id, name, code FROM hub_customer WHERE archived = 0 ORDER BY name ASC, code ASC');
  $customerOptions = $stmtCust ? $stmtCust->fetchAll(PDO::FETCH_ASSOC) : [];
}

$scopeCustomerLabel = '';
if ($scopeCustomerId !== null && $scopeCustomerId > 0) {
  $scopeCustomerLabel = 'Customer #' . (int) $scopeCustomerId;
  foreach ($customerOptions as $opt) {
    if ((int) ($opt['id'] ?? 0) === (int) $scopeCustomerId) {
      $name = trim((string) ($opt['name'] ?? ''));
      $code = trim((string) ($opt['code'] ?? ''));
      $scopeCustomerLabel = $name !== '' ? $name : ($code !== '' ? $code : $scopeCustomerLabel);
      if ($code !== '' && $name !== '') {
        $scopeCustomerLabel .= ' (' . $code . ')';
      }
      break;
    }
  }
}

$roleOptions = hub_user_role_options();
$currentUserRoleRank = hub_role_rank(hub_role_key($currentUser));
$assignableRoleOptions = array_values(array_filter($roleOptions, static function (array $roleOption) use ($currentUserRoleRank): bool {
  return hub_role_rank((string) ($roleOption['role_key'] ?? 'user')) <= $currentUserRoleRank;
}));
$roleLabelMap = [];
foreach ($roleOptions as $roleOption) {
  $roleKey = (string) ($roleOption['role_key'] ?? '');
  if ($roleKey !== '') {
    $roleLabelMap[$roleKey] = (string) ($roleOption['label'] ?? $roleKey);
  }
}

$importSourceOptions = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_import_source')) {
  $stmtSources = $pdo->query(
    'SELECT id, name, import_key, handler
     FROM hub_import_source
     WHERE archived = 0
     ORDER BY show_on_web DESC, name ASC, import_key ASC'
  );
  $importSourceOptions = $stmtSources ? ($stmtSources->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function hub_admin_users_can_assign_role(string $role): bool {
  global $currentUserRoleRank;
  return hub_role_rank($role) <= $currentUserRoleRank;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'super_reset_password') {
  if (!hub_is_super_admin()) {
    $error = 'Only Super Admins can reset user passwords.';
  } elseif (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user')) {
    $error = 'Database not available.';
  } else {
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $stmtTarget = $pdo->prepare('SELECT id, email, display_name, role FROM hub_user WHERE id = :id AND archived = 0 LIMIT 1');
    $stmtTarget->execute([':id' => $targetId]);
    $target = $stmtTarget->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$target) {
      $error = 'User not found.';
    } elseif (!hub_admin_users_can_assign_role((string) ($target['role'] ?? 'user'))) {
      $error = 'You cannot reset a user above your own access level.';
    } else {
      $temporaryPassword = hub_generate_temporary_password();
      $stmtReset = $pdo->prepare(
        'UPDATE hub_user SET password_hash = :password_hash, force_password_reset = 0, modified = NOW()
         WHERE id = :id AND archived = 0 LIMIT 1'
      );
      $stmtReset->execute([
        ':password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
        ':id' => $targetId,
      ]);

      $emailRequested = !empty($_POST['email_password']);
      $emailSent = false;
      if ($emailRequested) {
        $emailSent = hub_send_template_email(
          (string) $target['email'],
          HUB_APP_NAME . ' temporary password',
          hub_email_user_name($target),
          [
            'A Super Admin has reset your Hub password.',
            'Your temporary password is: ' . $temporaryPassword,
            'Sign in with your email address and this password. For security, change it using Forgotten Password after signing in.',
          ],
          hub_base_url('/login-password.php'),
          'Sign in to Hub'
        );
      }

      hub_log_user_action([
        'user' => $currentUser,
        'action_key' => 'super_admin_password_reset',
        'action_title' => 'Super Admin password reset',
        'table_name' => 'hub_user',
        'record_id' => $targetId,
        'sql_text' => 'UPDATE hub_user SET password_hash = [REDACTED], force_password_reset = 0, modified = NOW() WHERE id = :id',
        'details' => [
          'target_email' => (string) $target['email'],
          'email_requested' => $emailRequested,
          'email_sent' => $emailSent,
          'password_logged' => false,
        ],
      ]);
      $temporaryPasswordResult = [
        'user' => hub_admin_users_display_name($target),
        'email' => (string) $target['email'],
        'password' => $temporaryPassword,
        'email_requested' => $emailRequested,
        'email_sent' => $emailSent,
      ];
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
  if (!$canManageAllUsers) {
    $error = 'Managers can request new users, but only admins can create them.';
  } elseif (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } else {
    $email = trim((string) ($_POST['email'] ?? ''));
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $jobTitle = trim((string) ($_POST['job_title'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $linkedin = trim((string) ($_POST['linkedin'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = hub_valid_user_role_key((string) ($_POST['role'] ?? 'user'));
    $customerId = trim((string) ($_POST['customer_id'] ?? '')) === '' ? null : (int) $_POST['customer_id'];

    if (!hub_admin_users_can_assign_role($role)) {
      $error = 'You cannot assign a role above your own access level.';
    }
    $createErr = null;
    $newId = null;
    if ($error === null) {
      $data = [
        'email' => $email,
        'display_name' => $display,
        'job_title' => $jobTitle,
        'phone' => $phone,
        'password' => $password,
        'customer_id' => $customerId,
        'role' => $role,
      ];
      $newId = hub_create_user($data, $createErr);
    }
    if ($newId) {
      hub_log_user_action([
        'user' => $currentUser,
        'action_key' => 'admin_user_created',
        'action_title' => 'Admin user created',
        'table_name' => 'hub_user',
        'record_id' => $newId,
        'sql_text' => 'INSERT INTO hub_user (...)',
        'details' => ['target_email' => $email, 'role' => $role, 'customer_id' => $customerId],
      ]);
      hub_flash('success', 'User added (ID ' . $newId . ').');
      hub_redirect('/admin/users.php');
    } elseif ($error === null) {
      $error = $createErr ?: 'Unable to create user.';
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_user') {
  if (!$isCompanyManager) {
    $error = 'Only managers use the new user request form.';
  } elseif (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif ($scopeCustomerId === null || $scopeCustomerId <= 0) {
    $error = 'Your account is not assigned to a customer.';
  } else {
    $requestEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
    $requestName = trim((string) ($_POST['display_name'] ?? ''));
    $requestTitle = trim((string) ($_POST['job_title'] ?? ''));
    $requestPhone = trim((string) ($_POST['phone'] ?? ''));
    $requestLinkedin = trim((string) ($_POST['linkedin'] ?? ''));
    $requestNotes = trim((string) ($_POST['notes'] ?? ''));
    $adminEmail = trim((string) hub_pref('prefManagerEmail', ''));
    if ($adminEmail === '') {
      $adminEmail = trim((string) hub_pref('prefEmailFrom', ''));
    }

    if ($requestEmail === '' || !filter_var($requestEmail, FILTER_VALIDATE_EMAIL)) {
      $error = 'A valid email address is required.';
    } elseif ($adminEmail === '') {
      $error = 'Admin email preference is not configured.';
    } else {
      $customerLabel = 'Customer #' . (int) $scopeCustomerId;
      foreach ($customerOptions as $opt) {
        if ((int) ($opt['id'] ?? 0) === (int) $scopeCustomerId) {
          $name = trim((string) ($opt['name'] ?? ''));
          $code = trim((string) ($opt['code'] ?? ''));
          $customerLabel = $name !== '' ? $name : ($code !== '' ? $code : $customerLabel);
          if ($code !== '' && $name !== '') {
            $customerLabel .= ' (' . $code . ')';
          }
          break;
        }
      }

      $createErr = null;
      $temporaryPassword = bin2hex(random_bytes(12));
      $newId = hub_create_user([
        'email' => $requestEmail,
        'display_name' => $requestName,
        'job_title' => $requestTitle,
        'phone' => $requestPhone,
        'linkedin' => $requestLinkedin,
        'password' => $temporaryPassword,
        'customer_id' => $scopeCustomerId,
        'role' => 'user',
      ], $createErr);

      if (!$newId) {
        $error = $createErr ?: 'Unable to create pending user.';
      } else {
        $stmtPending = $pdo->prepare(
          'UPDATE hub_user
           SET login_enabled = 0,
               twofa_enabled = 1,
               force_password_reset = 1,
               modified = NOW()
           WHERE id = :id
           LIMIT 1'
        );
        $stmtPending->execute([':id' => $newId]);
        hub_log_user_action([
          'user' => $currentUser,
          'action_key' => 'manager_user_requested',
          'action_title' => 'Manager user requested',
          'table_name' => 'hub_user',
          'record_id' => $newId,
          'sql_text' => 'UPDATE hub_user SET login_enabled = 0, twofa_enabled = 1, force_password_reset = 1, modified = NOW() WHERE id = :id LIMIT 1',
          'details' => ['target_email' => $requestEmail, 'customer_id' => $scopeCustomerId],
        ]);

        $requester = hub_admin_users_display_name($currentUser ?: []);
        $message = 'Manager ' . $requester . ' created pending user ' . $requestEmail . ' for ' . $customerLabel . '. Verify the user details, then enable login when approved.';
        if ($requestNotes !== '') {
          $message .= "\n\nManager notes: " . $requestNotes;
        }

        if (hub_table_exists('hub_admin_action')) {
          $stmtAction = $pdo->prepare(
            'INSERT INTO hub_admin_action
              (action_key, action_type, entity_type, entity_id, assigned_user_id, title, message, status, priority, source, created, modified)
             VALUES
              (:action_key, :action_type, :entity_type, :entity_id, NULL, :title, :message, :status, :priority, :source, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
              entity_id = VALUES(entity_id),
              assigned_user_id = NULL,
              title = VALUES(title),
              message = VALUES(message),
              status = IF(status = "complete", status, VALUES(status)),
              priority = VALUES(priority),
              modified = NOW()'
          );
          $stmtAction->execute([
            ':action_key' => 'verify_user:' . (int) $newId,
            ':action_type' => 'verify_user',
            ':entity_type' => 'user',
            ':entity_id' => $newId,
            ':title' => 'Verify new user: ' . ($requestName !== '' ? $requestName : $requestEmail),
            ':message' => $message,
            ':status' => 'open',
            ':priority' => 'normal',
            ':source' => 'manager_users',
          ]);
        }

        $subject = 'Verify new Hub user - ' . $customerLabel;
        $pendingUserLabel = $requestName !== '' ? $requestName . ' <' . $requestEmail . '>' : $requestEmail;
        $emailParagraphs = [
          'A manager has created a pending Hub user for verification.',
          'Pending user: ' . $pendingUserLabel . "\nCustomer: " . $customerLabel . ($requestLinkedin !== '' ? "\nLinkedIn: " . $requestLinkedin : ''),
          'Requested by: ' . $requester . "\nRequester email: " . (string) ($currentUser['email'] ?? ''),
          'Login is disabled and 2FA is enabled. Please verify the user, then enable login if approved.',
        ];
        if ($requestNotes !== '') {
          $emailParagraphs[] = "Manager notes:\n" . $requestNotes;
        }

        hub_send_template_email($adminEmail, $subject, 'Admin', $emailParagraphs, hub_base_url('/admin/users.php'), 'Review user');
        hub_flash('success', 'Pending user created and sent for admin verification.');
        hub_redirect('/admin/users.php');
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['update_customer', 'update_user'], true)) {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user')) {
    $error = 'Database not available.';
  } else {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $jobTitle = trim((string) ($_POST['job_title'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $linkedin = trim((string) ($_POST['linkedin'] ?? ''));

    if ($userId <= 0) {
      $error = 'User not found.';
    } elseif ($canManageAllUsers) {
      $email = strtolower(trim((string) ($_POST['email'] ?? '')));
      $customerId = trim((string) ($_POST['customer_id'] ?? '')) === '' ? null : (int) $_POST['customer_id'];
      $role = hub_valid_user_role_key((string) ($_POST['role'] ?? 'user'));
      $loginEnabled = !empty($_POST['login_enabled']) ? 1 : 0;
      $twofaEnabled = !empty($_POST['twofa_enabled']) ? 1 : 0;
      if (!hub_admin_users_can_assign_role($role)) {
        $error = 'You cannot assign a role above your own access level.';
      } elseif ($email !== '') {
        $stmtTarget = $pdo->prepare(
          'SELECT u.id, u.email, u.display_name, u.customer_id, u.image, u.role, c.name AS customer_name, c.code AS customer_code
           FROM hub_user u
           LEFT JOIN hub_customer c ON c.id = u.customer_id
           WHERE u.id = :id AND u.archived = 0
           LIMIT 1'
        );
        $stmtTarget->execute([':id' => $userId]);
        $targetUser = $stmtTarget->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$targetUser) {
          $error = 'User not found.';
        } elseif (!hub_admin_users_can_assign_role((string) ($targetUser['role'] ?? 'user'))) {
          $error = 'You cannot edit a user above your own access level.';
        }
        $targetCustomer = null;
        foreach ($customerOptions as $opt) {
          if ($customerId !== null && (int) ($opt['id'] ?? 0) === (int) $customerId) {
            $targetCustomer = ['name' => (string) ($opt['name'] ?? ''), 'code' => (string) ($opt['code'] ?? '')];
            break;
          }
        }
        if ($targetCustomer === null && $targetUser) {
          $targetCustomer = ['name' => (string) ($targetUser['customer_name'] ?? ''), 'code' => (string) ($targetUser['customer_code'] ?? '')];
        }

        $imageShouldChange = !empty($_POST['remove_profile_image']);
        $imageFilename = null;
        $profileUpload = $_FILES['profile_image'] ?? null;
        if ($error === null && is_array($profileUpload) && (($profileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
          $namingUser = [
            'id' => $userId,
            'email' => $email,
            'display_name' => $display !== '' ? $display : (string) ($targetUser['display_name'] ?? ''),
          ];
          $uploadError = null;
          $imageFilename = hub_image_upload($profileUpload, 'user_profile', hub_image_user_base_name($namingUser, $targetCustomer), $uploadError);
          if ($imageFilename === null) {
            $error = $uploadError ?: 'Unable to upload profile image.';
          } else {
            $imageShouldChange = true;
          }
        }

        if ($error === null) {
          $imageSql = $imageShouldChange ? ',
               image = :image' : '';
          $stmtUpdate = $pdo->prepare(
            'UPDATE hub_user
           SET email = :email,
               display_name = :display_name,
               customer_id = :customer_id,
               job_title = :job_title,
               phone = :phone,
               linkedin = :linkedin,
               role = :role,
               login_enabled = :login_enabled,
               twofa_enabled = :twofa_enabled' . $imageSql . ',
               modified = NOW()
           WHERE id = :id
           LIMIT 1'
          );
          $params = [
            ':email' => $email,
            ':display_name' => $display !== '' ? $display : null,
            ':customer_id' => $customerId,
            ':job_title' => $jobTitle !== '' ? $jobTitle : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':linkedin' => $linkedin !== '' ? $linkedin : null,
            ':role' => $role,
            ':login_enabled' => $loginEnabled,
            ':twofa_enabled' => $twofaEnabled,
            ':id' => $userId,
          ];
          if ($imageShouldChange) {
            $params[':image'] = $imageFilename;
          }
          try {
            $stmtUpdate->execute($params);
            hub_admin_users_save_source_access($userId, $role, $importSourceOptions);
            if (hub_role_rank($role) >= hub_role_rank('manager') && hub_table_exists('hub_user_project_access')) {
              $stmtProjectAccessDelete = $pdo->prepare('DELETE FROM hub_user_project_access WHERE user_id = :user_id');
              $stmtProjectAccessDelete->execute([':user_id' => $userId]);
            }
            hub_log_user_action([
              'user' => $currentUser,
              'action_key' => 'admin_user_updated',
              'action_title' => 'Admin user updated',
              'table_name' => 'hub_user',
              'record_id' => $userId,
              'sql_text' => 'UPDATE hub_user SET email = :email, display_name = :display_name, customer_id = :customer_id, job_title = :job_title, phone = :phone, linkedin = :linkedin, role = :role, login_enabled = :login_enabled, twofa_enabled = :twofa_enabled, modified = NOW() WHERE id = :id LIMIT 1',
              'sql_params' => $params,
              'details' => ['target_email' => $email, 'role' => $role, 'login_enabled' => $loginEnabled, 'twofa_enabled' => $twofaEnabled],
            ]);
            hub_flash('success', 'User details updated.');
            hub_redirect('/admin/users.php');
          } catch (PDOException $e) {
            $error = 'Unable to update user. The email may already be in use.';
          }
        }
      } else {
        $error = 'Email is required.';
      }
    } else {
      $stmtTarget = $pdo->prepare(
        'SELECT u.id, u.email, u.display_name, u.customer_id, u.image, u.role, c.name AS customer_name, c.code AS customer_code
         FROM hub_user u
         LEFT JOIN hub_customer c ON c.id = u.customer_id
         WHERE u.id = :id AND u.customer_id = :customer_id AND u.archived = 0
         LIMIT 1'
      );
      $stmtTarget->execute([':id' => $userId, ':customer_id' => $scopeCustomerId]);
      $targetUser = $stmtTarget->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$targetUser) {
        $error = 'User not found for your company.';
      } elseif (!hub_admin_users_can_assign_role((string) ($targetUser['role'] ?? 'user'))) {
        $error = 'You cannot edit a user above your own access level.';
      } else {
        $imageShouldChange = !empty($_POST['remove_profile_image']);
        $imageFilename = null;
        $profileUpload = $_FILES['profile_image'] ?? null;
        if (is_array($profileUpload) && (($profileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
          $namingUser = [
            'id' => $userId,
            'email' => (string) ($targetUser['email'] ?? ''),
            'display_name' => $display !== '' ? $display : (string) ($targetUser['display_name'] ?? ''),
          ];
          $targetCustomer = ['name' => (string) ($targetUser['customer_name'] ?? ''), 'code' => (string) ($targetUser['customer_code'] ?? '')];
          $uploadError = null;
          $imageFilename = hub_image_upload($profileUpload, 'user_profile', hub_image_user_base_name($namingUser, $targetCustomer), $uploadError);
          if ($imageFilename === null) {
            $error = $uploadError ?: 'Unable to upload profile image.';
          } else {
            $imageShouldChange = true;
          }
        }

        if ($error === null) {
          $imageSql = $imageShouldChange ? ',
             image = :image' : '';
          $stmtUpdate = $pdo->prepare(
            'UPDATE hub_user
         SET display_name = :display_name,
             job_title = :job_title,
             phone = :phone,
             linkedin = :linkedin' . $imageSql . ',
             modified = NOW()
         WHERE id = :id
           AND customer_id = :customer_id
           AND archived = 0
         LIMIT 1'
          );
          $params = [
            ':display_name' => $display !== '' ? $display : null,
            ':job_title' => $jobTitle !== '' ? $jobTitle : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':linkedin' => $linkedin !== '' ? $linkedin : null,
            ':id' => $userId,
            ':customer_id' => $scopeCustomerId,
          ];
          if ($imageShouldChange) {
            $params[':image'] = $imageFilename;
          }
          $stmtUpdate->execute($params);
          hub_admin_users_save_source_access($userId, (string) ($targetUser['role'] ?? 'user'), $importSourceOptions);
          hub_log_user_action([
            'user' => $currentUser,
            'action_key' => 'manager_user_updated',
            'action_title' => 'Manager user updated',
            'table_name' => 'hub_user',
            'record_id' => $userId,
            'sql_text' => 'UPDATE hub_user SET display_name = :display_name, job_title = :job_title, phone = :phone, linkedin = :linkedin, modified = NOW() WHERE id = :id AND customer_id = :customer_id AND archived = 0 LIMIT 1',
            'sql_params' => $params,
            'details' => ['customer_id' => $scopeCustomerId, 'profile_image_changed' => $imageShouldChange],
          ]);
          hub_flash('success', 'User details updated.');
          hub_redirect('/admin/users.php');
        }
      }
    }
  }
}

$userColumnFilters = is_array($_GET['col'] ?? null) ? $_GET['col'] : [];
$userColumnFilters = array_map(static function ($value): string {
  return trim((string) $value);
}, $userColumnFilters);
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min(200, $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = trim((string) ($_GET['sort'] ?? 'id_asc'));
$allowedSortColumns = array_fill_keys(['id', 'name', 'email', 'role', 'customer', 'twofa', 'login', 'project_access'], true);
$totalRows = 0;
$totalPages = 1;
$filterOptions = [
  'name' => [],
  'role' => [],
  'customer' => [],
  'twofa' => [],
  'login' => [],
  'project_access' => [],
];

function hub_admin_users_display_name(array $row): string {
  $name = trim((string) ($row['display_name'] ?? ''));
  return $name !== '' ? $name : trim((string) ($row['email'] ?? ''));
}

function hub_admin_users_customer_label(array $row): string {
  return trim((string) (($row['customer_name'] ?? '') ?: ($row['customer_code'] ?? '')));
}

function hub_admin_users_row_value(array $row, string $column): string {
  global $roleLabelMap;

  if ($column === 'name') {
    return hub_admin_users_display_name($row);
  }
  if ($column === 'role') {
    $role = trim((string) ($row['role'] ?? ''));
    return $roleLabelMap[$role] ?? $role;
  }
  if ($column === 'customer') {
    return hub_admin_users_customer_label($row);
  }
  if ($column === 'twofa') {
    return ((int) ($row['twofa_enabled'] ?? 0) === 1) ? 'On' : 'Off';
  }
  if ($column === 'login') {
    return ((int) ($row['login_enabled'] ?? 0) === 1) ? 'On' : 'Off';
  }
  if ($column === 'project_access') {
    $explicitCount = (int) ($row['project_access_rows'] ?? 0);
    if ((string) ($row['role'] ?? 'user') !== 'user') {
      return 'All Selected';
    }
    if ($explicitCount === 0) {
      return 'All projects';
    }
    $allowedCount = (int) ($row['project_access_allowed'] ?? 0);
    return $allowedCount . ' selected';
  }
  return trim((string) ($row[$column] ?? ''));
}

function hub_admin_users_row_matches_filters(array $row, array $filters, ?string $exceptColumn = null): bool {
  foreach ($filters as $column => $filter) {
    $column = (string) $column;
    if ($exceptColumn !== null && $column === $exceptColumn) {
      continue;
    }
    $filter = trim((string) $filter);
    if ($filter === '') {
      continue;
    }
    $value = hub_admin_users_row_value($row, $column);
    if (in_array($column, ['id', 'name', 'email'], true)) {
      if (mb_strpos(mb_strtolower($value), mb_strtolower($filter)) === false) {
        return false;
      }
      continue;
    }
    if ($value !== $filter) {
      return false;
    }
  }
  return true;
}

function hub_admin_users_has_filters(array $filters): bool {
  foreach ($filters as $filter) {
    if (trim((string) $filter) !== '') {
      return true;
    }
  }
  return false;
}

function hub_admin_users_url(array $params): string {
  $current = [
    'page' => $_GET['page'] ?? 1,
    'per_page' => $_GET['per_page'] ?? 50,
    'col' => $_GET['col'] ?? [],
    'sort' => $_GET['sort'] ?? 'id_asc',
  ];
  return '/admin/users.php?' . http_build_query(array_merge($current, $params));
}

function hub_admin_users_sort_link(string $column, string $label, string $currentSort): string {
  $ascKey = $column . '_asc';
  $descKey = $column . '_desc';
  $next = ($currentSort === $ascKey) ? $descKey : $ascKey;
  $arrow = ' ↕';
  if ($currentSort === $ascKey) {
    $arrow = ' ▲';
  } elseif ($currentSort === $descKey) {
    $arrow = ' ▼';
  }
  return '<a href="' . hub_h(hub_admin_users_url(['sort' => $next, 'page' => 1])) . '">' . hub_h($label) . $arrow . '</a>';
}

function hub_admin_users_source_label(array $source): string {
  $name = trim((string) ($source['name'] ?? ''));
  $key = trim((string) ($source['import_key'] ?? ''));
  if ($name === '') {
    $name = $key !== '' ? $key : 'Source #' . (int) ($source['id'] ?? 0);
  }
  return $key !== '' && strcasecmp($name, $key) !== 0 ? $name . ' (' . $key . ')' : $name;
}

function hub_admin_users_save_source_access(int $userId, string $targetRole, array $sourceOptions): void {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user_import_source_access')) {
    return;
  }
  if ($userId <= 0) {
    return;
  }
  if (hub_role_rank($targetRole) >= hub_role_rank('manager')) {
    $stmtDelete = $pdo->prepare('DELETE FROM hub_user_import_source_access WHERE user_id = :user_id');
    $stmtDelete->execute([':user_id' => $userId]);
    return;
  }

  if (empty($_POST['source_access_managed'])) {
    return;
  }

  $sourceIds = [];
  foreach ($sourceOptions as $source) {
    $sourceId = (int) ($source['id'] ?? 0);
    if ($sourceId > 0) {
      $sourceIds[] = $sourceId;
    }
  }
  if (empty($sourceIds)) {
    return;
  }

  $postedAllowed = $_POST['source_access'] ?? [];
  $allowed = [];
  if (is_array($postedAllowed)) {
    foreach ($postedAllowed as $sourceId) {
      $allowed[(int) $sourceId] = true;
    }
  }

  $allAllowed = true;
  foreach ($sourceIds as $sourceId) {
    if (empty($allowed[$sourceId])) {
      $allAllowed = false;
      break;
    }
  }

  $stmtDelete = $pdo->prepare('DELETE FROM hub_user_import_source_access WHERE user_id = :user_id');
  $stmtDelete->execute([':user_id' => $userId]);
  if ($allAllowed) {
    return;
  }

  $stmtInsert = $pdo->prepare(
    'INSERT INTO hub_user_import_source_access
      (user_id, source_id, can_access, created, modified)
     VALUES
      (:user_id, :source_id, :can_access, NOW(), NOW())'
  );
  foreach ($sourceIds as $sourceId) {
    $stmtInsert->execute([
      ':user_id' => $userId,
      ':source_id' => $sourceId,
      ':can_access' => !empty($allowed[$sourceId]) ? 1 : 0,
    ]);
  }
}

// List users.
$allUsers = [];
$users = [];
if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_user')) {
  $sqlUsers = 'SELECT u.id, u.email, u.display_name, u.job_title, u.phone, u.linkedin, u.role, u.customer_id, u.image, u.login_enabled, u.twofa_enabled, u.created, c.code AS customer_code, c.name AS customer_name, COUNT(upa.id) AS project_access_rows, SUM(CASE WHEN upa.can_access = 1 THEN 1 ELSE 0 END) AS project_access_allowed
     FROM hub_user u
     LEFT JOIN hub_customer c ON c.id = u.customer_id
     LEFT JOIN hub_user_project_access upa ON upa.user_id = u.id
     WHERE u.archived = 0';
  $queryParams = [];
  if (!$canManageAllUsers) {
    if ($scopeCustomerId === null || $scopeCustomerId <= 0) {
      $sqlUsers .= ' AND 1 = 0';
    } else {
      $sqlUsers .= ' AND u.customer_id = :customer_id';
      $queryParams[':customer_id'] = $scopeCustomerId;
    }
  }
  $sqlUsers .= ' GROUP BY u.id, u.email, u.display_name, u.job_title, u.phone, u.linkedin, u.role, u.customer_id, u.image, u.login_enabled, u.twofa_enabled, u.created, c.code, c.name ORDER BY u.id DESC LIMIT 1000';
  $stmtUsers = $pdo->prepare($sqlUsers);
  $stmtUsers->execute($queryParams);
  $allUsers = $stmtUsers ? ($stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

  $userSourceAccess = [];
  if (!empty($allUsers) && hub_table_exists('hub_user_import_source_access')) {
    $userIds = array_map(static function (array $row): int {
      return (int) ($row['id'] ?? 0);
    }, $allUsers);
    $userIds = array_values(array_filter(array_unique($userIds)));
    if (!empty($userIds)) {
      $placeholders = [];
      $accessParams = [];
      foreach ($userIds as $index => $id) {
        $key = ':user_id_' . $index;
        $placeholders[] = $key;
        $accessParams[$key] = $id;
      }
      $stmtAccess = $pdo->prepare(
        'SELECT user_id, source_id, can_access
         FROM hub_user_import_source_access
         WHERE user_id IN (' . implode(', ', $placeholders) . ')'
      );
      $stmtAccess->execute($accessParams);
      foreach ($stmtAccess->fetchAll(PDO::FETCH_ASSOC) ?: [] as $accessRow) {
        $uid = (int) ($accessRow['user_id'] ?? 0);
        $sid = (int) ($accessRow['source_id'] ?? 0);
        if ($uid > 0 && $sid > 0) {
          $userSourceAccess[$uid][$sid] = (int) ($accessRow['can_access'] ?? 0);
        }
      }
    }
  }

  foreach (array_keys($filterOptions) as $field) {
    foreach ($allUsers as $row) {
      if (!hub_admin_users_row_matches_filters($row, $userColumnFilters, $field)) {
        continue;
      }
      $value = hub_admin_users_row_value($row, $field);
      if ($value !== '') {
        $filterOptions[$field][$value] = true;
      }
    }
    ksort($filterOptions[$field], SORT_NATURAL | SORT_FLAG_CASE);
  }

  $filteredUsers = [];
  foreach ($allUsers as $row) {
    if (hub_admin_users_row_matches_filters($row, $userColumnFilters)) {
      $filteredUsers[] = $row;
    }
  }

  $sortParts = explode('_', $sort);
  $sortDirection = array_pop($sortParts);
  $sortColumn = implode('_', $sortParts);
  if (!isset($allowedSortColumns[$sortColumn]) || !in_array($sortDirection, ['asc', 'desc'], true)) {
    $sortColumn = 'id';
    $sortDirection = 'asc';
    $sort = 'id_asc';
  }
  usort($filteredUsers, static function (array $left, array $right) use ($sortColumn, $sortDirection): int {
    $leftValue = hub_admin_users_row_value($left, $sortColumn);
    $rightValue = hub_admin_users_row_value($right, $sortColumn);
    if (is_numeric($leftValue) && is_numeric($rightValue)) {
      $result = ((float) $leftValue) <=> ((float) $rightValue);
    } else {
      $result = strnatcasecmp((string) $leftValue, (string) $rightValue);
    }
    return $sortDirection === 'desc' ? -$result : $result;
  });

  $totalRows = count($filteredUsers);
  $totalPages = max(1, (int) ceil($totalRows / $perPage));
  $page = min($page, $totalPages);
  $offset = ($page - 1) * $perPage;
  $users = array_slice($filteredUsers, $offset, $perPage);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Users</title>
  <link rel="stylesheet" href="/css/hub.css?v=20260622-password-reveal">
</head>
<body class="admin-page users-page">
  <?php echo hub_admin_header($headerActive); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card admin-users-add">
        <div class="flex">
          <div>
            <p class="brand"><?php echo hub_h($sectionLabel); ?></p>
            <h1>Users</h1>
            <p class="muted"><?php echo $canManageAllUsers ? 'Create users and attach them to customers.' : 'Manage users for your company and request new user setup.'; ?></p>
          </div>
          <div class="links">
            <a href="<?php echo hub_h($backUrl); ?>"><?php echo hub_h($backLabel); ?></a>
            <a href="/dashboard.php">Dashboard</a>
            <?php if ($canManageAllUsers): ?>
              <button id="new-user" type="button" data-open-modal="userAddModal">Add New User</button>
            <?php else: ?>
              <button id="new-user" type="button" data-open-modal="userRequestModal">Add New User</button>
            <?php endif; ?>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endif; ?>
        <?php if ($temporaryPasswordResult): ?>
          <div class="alert <?php echo !$temporaryPasswordResult['email_requested'] || $temporaryPasswordResult['email_sent'] ? 'success' : 'error'; ?>">
            Password reset for <?php echo hub_h($temporaryPasswordResult['user']); ?> (<?php echo hub_h($temporaryPasswordResult['email']); ?>).
            <?php if ($temporaryPasswordResult['email_requested']): ?><?php echo $temporaryPasswordResult['email_sent'] ? 'The email was sent.' : 'The email could not be sent; copy the password below.'; ?><?php endif; ?>
          </div>
          <div class="admin-temporary-password-result">
            <label for="generated-temporary-password">Temporary password — shown once</label>
            <div class="flex">
              <input id="generated-temporary-password" type="text" readonly value="<?php echo hub_h($temporaryPasswordResult['password']); ?>">
              <button class="but2" type="button" data-copy-target="generated-temporary-password">Copy password</button>
            </div>
          </div>
        <?php endif; ?>


      </div>

      <div class="card admin-users-list">
        <p class="brand">List</p>
        <h2>Existing users</h2>
        <form method="get" action="/admin/users.php" class="import-data-controls admin-table-controls" id="users-filter-form">
          <div>
            <label for="per_page">Rows Per Page</label>
            <select id="per_page" name="per_page" onchange="this.form.submit()">
              <?php foreach ([10, 25, 50, 100, 200] as $option): ?>
                <option value="<?php echo $option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <input type="hidden" name="page" value="1">
          <input type="hidden" name="sort" value="<?php echo hub_h($sort); ?>">
          <?php if (hub_admin_users_has_filters($userColumnFilters)): ?>
            <div class="links import-data-reset">
              <a href="/admin/users.php?per_page=<?php echo (int) $perPage; ?>&sort=<?php echo urlencode($sort); ?>">Clear Filters</a>
            </div>
          <?php endif; ?>
        </form>

        <?php if (empty($allUsers)): ?>
          <div class="alert info">No Users Found.</div>
        <?php else: ?>
          <div class="import-data-table-wrap">
            <table class="table users-table import-data-table">
              <thead>
                <tr>
                  <th><?php echo hub_admin_users_sort_link('id', 'ID', $sort); ?></th>
                  <th><?php echo hub_admin_users_sort_link('name', 'Name', $sort); ?></th>
                  <th><?php echo hub_admin_users_sort_link('email', 'Email', $sort); ?></th>
                  <th><?php echo hub_admin_users_sort_link('role', 'Role', $sort); ?></th>
                  <th><?php echo hub_admin_users_sort_link('customer', 'Customer', $sort); ?></th>
                  <th><?php echo hub_admin_users_sort_link('twofa', '2FA', $sort); ?></th>
                  <th><?php echo hub_admin_users_sort_link('login', 'Login', $sort); ?></th>
                  <th><?php echo hub_admin_users_sort_link('project_access', 'Projects', $sort); ?></th>
                  <th class="table-actions-heading">Action</th>
                </tr>
                <tr class="import-data-filter-row">
                  <th>
                    <input name="col[id]" form="users-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($userColumnFilters['id'] ?? '')); ?>" placeholder="Search">
                  </th>
                  <th>
                    <input name="col[name]" form="users-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($userColumnFilters['name'] ?? '')); ?>" placeholder="Search">
                  </th>
                  <th>
                    <input name="col[email]" form="users-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($userColumnFilters['email'] ?? '')); ?>" placeholder="Search">
                  </th>
                  <th>
                    <select name="col[role]" form="users-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (array_keys($filterOptions['role']) as $option): ?>
                        <option value="<?php echo hub_h($option); ?>" <?php echo (($userColumnFilters['role'] ?? '') === $option) ? 'selected' : ''; ?>><?php echo hub_h($option); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th>
                    <select name="col[customer]" form="users-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (array_keys($filterOptions['customer']) as $option): ?>
                        <option value="<?php echo hub_h($option); ?>" <?php echo (($userColumnFilters['customer'] ?? '') === $option) ? 'selected' : ''; ?>><?php echo hub_h($option); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th>
                    <select name="col[twofa]" form="users-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (array_keys($filterOptions['twofa']) as $option): ?>
                        <option value="<?php echo hub_h($option); ?>" <?php echo (($userColumnFilters['twofa'] ?? '') === $option) ? 'selected' : ''; ?>><?php echo hub_h($option); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th>
                    <select name="col[login]" form="users-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (array_keys($filterOptions['login']) as $option): ?>
                        <option value="<?php echo hub_h($option); ?>" <?php echo (($userColumnFilters['login'] ?? '') === $option) ? 'selected' : ''; ?>><?php echo hub_h($option); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th>
                    <select name="col[project_access]" form="users-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (array_keys($filterOptions['project_access']) as $option): ?>
                        <option value="<?php echo hub_h($option); ?>" <?php echo (($userColumnFilters['project_access'] ?? '') === $option) ? 'selected' : ''; ?>><?php echo hub_h($option); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th class="table-actions-heading"><i class="fa-solid fa-pencil" aria-hidden="true"></i></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($users)): ?>
                  <tr><td colspan="9" class="muted">No Users Found.</td></tr>
                <?php else: ?>
                <?php foreach ($users as $usr): ?>
                  <?php
                    $modalId = 'userEditModal' . (int) $usr['id'];
                    $customerLabel = hub_admin_users_customer_label($usr);
                    $canEditUser = hub_admin_users_can_assign_role((string) ($usr['role'] ?? 'user'));
                  ?>
                  <tr>
                    <td><?php echo (int) $usr['id']; ?></td>
                    <td><?php echo hub_h(hub_admin_users_display_name($usr)); ?></td>
                    <td><a class="email-link" href="mailto:<?php echo hub_h((string) $usr['email']); ?>"><?php echo hub_h((string) $usr['email']); ?></a></td>
                    <td><?php echo hub_h(hub_admin_users_row_value($usr, 'role')); ?></td>
                    <td><?php echo hub_h($customerLabel); ?></td>
                    <td><?php echo ((int) ($usr['twofa_enabled'] ?? 0) === 1) ? 'On' : 'Off'; ?></td>
                    <td><?php echo ((int) ($usr['login_enabled'] ?? 0) === 1) ? 'On' : 'Off'; ?></td>
                    <td><?php echo hub_h(hub_admin_users_row_value($usr, 'project_access')); ?></td>
                    <td class="table-actions">
                      <?php if ($canEditUser): ?>
                        <button type="button" class="icon-action" data-open-modal="<?php echo hub_h($modalId); ?>" title="Edit <?php echo hub_h(hub_admin_users_display_name($usr)); ?>" aria-label="Edit <?php echo hub_h(hub_admin_users_display_name($usr)); ?>"><i class="fa-solid fa-pencil" aria-hidden="true"></i></button>
                        <a class="icon-action icon-action-secondary" href="/admin/user-project-access.php?user_id=<?php echo (int) $usr['id']; ?>" title="Project access for <?php echo hub_h(hub_admin_users_display_name($usr)); ?>" aria-label="Project access for <?php echo hub_h(hub_admin_users_display_name($usr)); ?>"><i class="fa-solid fa-diagram-project" aria-hidden="true"></i></a>
                        <?php if (hub_is_super_admin()): ?>
                          <button type="button" class="icon-action" data-open-modal="passwordResetModal<?php echo (int) $usr['id']; ?>" title="Reset password for <?php echo hub_h(hub_admin_users_display_name($usr)); ?>" aria-label="Reset password for <?php echo hub_h(hub_admin_users_display_name($usr)); ?>"><i class="fa-solid fa-key" aria-hidden="true"></i></button>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="muted">Restricted</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalRows > 0): ?>
            <nav class="import-data-pagination" aria-label="User pages">
              <div class="links">
                <?php if ($page > 1): ?>
                  <a href="<?php echo hub_h(hub_admin_users_url(['page' => 1])); ?>">First</a>
                  <a href="<?php echo hub_h(hub_admin_users_url(['page' => $page - 1])); ?>">Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></span>
                <?php if ($page < $totalPages): ?>
                  <a href="<?php echo hub_h(hub_admin_users_url(['page' => $page + 1])); ?>">Next</a>
                  <a href="<?php echo hub_h(hub_admin_users_url(['page' => $totalPages])); ?>">Last</a>
                <?php endif; ?>
              </div>
            </nav>
          <?php endif; ?>

          <?php foreach ($users as $usr): ?>
            <?php
              $modalId = 'userEditModal' . (int) $usr['id'];
              $canEditUser = hub_admin_users_can_assign_role((string) ($usr['role'] ?? 'user'));
              if (!$canEditUser) {
                continue;
              }
            ?>
            <div class="modal admin-entity-modal" id="<?php echo hub_h($modalId); ?>" aria-hidden="true">
              <div class="modal-content">
                <div class="modal-header">
                  <h2>Edit User</h2>
                  <button type="button" class="close-btn but2" data-close-modal="<?php echo hub_h($modalId); ?>" aria-label="Close">&times;</button>
                </div>
                <form method="post" action="/admin/users.php" enctype="multipart/form-data">
                  <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                  <input type="hidden" name="action" value="update_user">
                  <input type="hidden" name="user_id" value="<?php echo (int) $usr['id']; ?>">
                  <input type="hidden" name="current_role" value="<?php echo hub_h((string) ($usr['role'] ?? 'user')); ?>">
                  <?php if ($canManageAllUsers): ?>
                    <div>
                      <label for="edit_user_email_<?php echo (int) $usr['id']; ?>">Email *</label>
                      <input id="edit_user_email_<?php echo (int) $usr['id']; ?>" name="email" type="email" value="<?php echo hub_h((string) ($usr['email'] ?? '')); ?>" required>
                    </div>
                  <?php else: ?>
                    <div>
                      <label>Email</label>
                      <input type="email" value="<?php echo hub_h((string) ($usr['email'] ?? '')); ?>" readonly>
                    </div>
                  <?php endif; ?>
                  <div>
                    <label for="edit_user_display_<?php echo (int) $usr['id']; ?>">Name</label>
                    <input id="edit_user_display_<?php echo (int) $usr['id']; ?>" name="display_name" type="text" value="<?php echo hub_h((string) ($usr['display_name'] ?? '')); ?>">
                  </div>
                  <div>
                    <label for="edit_user_title_<?php echo (int) $usr['id']; ?>">Title</label>
                    <input id="edit_user_title_<?php echo (int) $usr['id']; ?>" name="job_title" type="text" value="<?php echo hub_h((string) ($usr['job_title'] ?? '')); ?>">
                  </div>
                  <div>
                    <label for="edit_user_phone_<?php echo (int) $usr['id']; ?>">Telephone</label>
                    <input id="edit_user_phone_<?php echo (int) $usr['id']; ?>" name="phone" type="tel" value="<?php echo hub_h((string) ($usr['phone'] ?? '')); ?>">
                  </div>
                  <div>
                    <label for="edit_user_linkedin_<?php echo (int) $usr['id']; ?>">LinkedIn URL</label>
                    <input id="edit_user_linkedin_<?php echo (int) $usr['id']; ?>" name="linkedin" type="url" value="<?php echo hub_h((string) ($usr['linkedin'] ?? '')); ?>" placeholder="https://www.linkedin.com/in/name">
                  </div>
                  <div class="user-image-upload-panel">
                    <label for="edit_user_image_<?php echo (int) $usr['id']; ?>">Profile Image</label>
                    <?php $currentImage = trim((string) ($usr['image'] ?? '')); ?>
                    <?php if ($currentImage !== ''): ?>
                      <div class="user-image-current">
                        <img src="<?php echo hub_h(hub_image_public_path('content', 'xs', $currentImage)); ?>" alt="">
                        <span><?php echo hub_h($currentImage); ?></span>
                      </div>
                      <label class="muted user-image-remove"><input type="checkbox" name="remove_profile_image" value="1"> Remove current image</label>
                    <?php else: ?>
                      <p class="muted">No profile image uploaded.</p>
                    <?php endif; ?>
                    <input id="edit_user_image_<?php echo (int) $usr['id']; ?>" name="profile_image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
                    <p class="muted">Saves 1200px, 150px and 75px versions without enlarging smaller images.</p>
                  </div>
                  <?php if ($canManageAllUsers): ?>
                    <div>
                      <label for="edit_user_role_<?php echo (int) $usr['id']; ?>">Role</label>
                      <select id="edit_user_role_<?php echo (int) $usr['id']; ?>" name="role">
                        <?php foreach ($assignableRoleOptions as $roleOption): ?>
                          <?php $selectedRole = (($usr['role'] ?? '') === ($roleOption['role_key'] ?? '')) ? 'selected' : ''; ?>
                          <option value="<?php echo hub_h((string) $roleOption['role_key']); ?>" <?php echo $selectedRole; ?>><?php echo hub_h((string) $roleOption['label']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label for="edit_user_customer_<?php echo (int) $usr['id']; ?>">Customer</label>
                      <select id="edit_user_customer_<?php echo (int) $usr['id']; ?>" name="customer_id">
                        <option value="">-- None --</option>
                        <?php foreach ($customerOptions as $opt): ?>
                          <?php
                            $label = trim((string) ($opt['name'] ?? ''));
                            $code = (string) ($opt['code'] ?? '');
                            $label = $label !== '' ? $label . ' (' . $code . ')' : $code;
                            $selected = ((int) ($usr['customer_id'] ?? 0) === (int) $opt['id']) ? 'selected' : '';
                          ?>
                          <option value="<?php echo (int) $opt['id']; ?>" <?php echo $selected; ?>><?php echo hub_h($label); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="modal-checkbox-row">
                      <label class="muted"><input type="checkbox" name="login_enabled" value="1" <?php echo ((int) ($usr['login_enabled'] ?? 0) === 1) ? 'checked' : ''; ?>> Login</label>
                      <label class="muted"><input type="checkbox" name="twofa_enabled" value="1" <?php echo ((int) ($usr['twofa_enabled'] ?? 0) === 1) ? 'checked' : ''; ?>> 2FA</label>
                    </div>
                  <?php else: ?>
                    <div>
                      <label>Role</label>
                      <input type="text" value="<?php echo hub_h(hub_admin_users_row_value($usr, 'role')); ?>" readonly>
                    </div>
                    <div>
                      <label>Customer</label>
                      <input type="text" value="<?php echo hub_h(hub_admin_users_customer_label($usr)); ?>" readonly>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($importSourceOptions)): ?>
                    <?php
                      $sourceAccessForUser = $userSourceAccess[(int) $usr['id']] ?? [];
                      $hasExplicitSourceAccess = !empty($sourceAccessForUser);
                      $isSourceRestrictedRole = ((string) ($usr['role'] ?? 'user') === 'user');
                    ?>
                    <div class="source-access-panel">
                      <label>Source Access</label>
                      <?php if ($isSourceRestrictedRole): ?>
                        <input type="hidden" name="source_access_managed" value="1">
                        <div class="source-access-list">
                          <?php foreach ($importSourceOptions as $source): ?>
                            <?php
                              $sourceId = (int) ($source['id'] ?? 0);
                              $checked = !$hasExplicitSourceAccess || ((int) ($sourceAccessForUser[$sourceId] ?? 0) === 1);
                            ?>
                            <label class="muted">
                              <input type="checkbox" name="source_access[]" value="<?php echo $sourceId; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                              <?php echo hub_h(hub_admin_users_source_label($source)); ?>
                            </label>
                          <?php endforeach; ?>
                        </div>
                        <p class="muted"><?php echo $hasExplicitSourceAccess ? 'Explicit source access is active for this user.' : 'All sources are allowed by default until one is unticked and saved.'; ?></p>
                      <?php else: ?>
                        <p class="muted">Source restrictions only apply to User role accounts.</p>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <div class="links" style="justify-content:flex-start;">
                    <button type="submit">Save User</button>
                  </div>
                </form>
              </div>
            </div>
            <?php if (hub_is_super_admin()): ?>
              <div class="modal admin-entity-modal" id="passwordResetModal<?php echo (int) $usr['id']; ?>" aria-hidden="true">
                <div class="modal-content">
                  <div class="modal-header">
                    <h2>Reset Password</h2>
                    <button type="button" class="close-btn but2" data-close-modal="passwordResetModal<?php echo (int) $usr['id']; ?>" aria-label="Close">&times;</button>
                  </div>
                  <p>Generate a new temporary password for <strong><?php echo hub_h(hub_admin_users_display_name($usr)); ?></strong>.</p>
                  <p class="muted">The current password will stop working immediately. The generated password will be shown once after reset.</p>
                  <form method="post" action="/admin/users.php">
                    <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                    <input type="hidden" name="action" value="super_reset_password">
                    <input type="hidden" name="user_id" value="<?php echo (int) $usr['id']; ?>">
                    <label class="muted"><input type="checkbox" name="email_password" value="1"> Email the temporary password to <?php echo hub_h((string) $usr['email']); ?></label>
                    <div class="links"><button class="but1" type="submit">Reset Password</button></div>
                  </form>
                </div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
  <?php if ($canManageAllUsers): ?>
  <div class="modal admin-entity-modal" id="userAddModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Add New User</h2>
        <button type="button" class="close-btn but2" data-close-modal="userAddModal" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="/admin/users.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="add_user">
        <div>
          <label for="user_email">Email (Username) *</label>
          <input id="user_email" name="email" type="email" required>
        </div>
        <div>
          <label for="user_display_name">Display Name</label>
          <input id="user_display_name" name="display_name" type="text">
        </div>
        <div>
          <label for="user_job_title">Title</label>
          <input id="user_job_title" name="job_title" type="text">
        </div>
        <div>
          <label for="user_phone">Telephone</label>
          <input id="user_phone" name="phone" type="tel">
        </div>
        <div>
          <label for="user_password">Temporary Password *</label>
          <div class="password-field">
            <input id="user_password" name="password" type="password" required>
            <button class="password-reveal" type="button" aria-label="Show password while hovered" aria-pressed="false" data-password-reveal onmouseenter="this.previousElementSibling.type='text'; this.classList.add('is-visible');" onmouseleave="if (this.getAttribute('aria-pressed') !== 'true') { this.previousElementSibling.type='password'; this.classList.remove('is-visible'); }" onfocus="this.previousElementSibling.type='text'; this.classList.add('is-visible');" onblur="if (this.getAttribute('aria-pressed') !== 'true') { this.previousElementSibling.type='password'; this.classList.remove('is-visible'); }" onclick="var visible=this.getAttribute('aria-pressed') !== 'true'; this.setAttribute('aria-pressed', visible ? 'true' : 'false'); this.previousElementSibling.type=visible ? 'text' : 'password'; this.classList.toggle('is-visible', visible);">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
          <p class="muted">Min <?php echo hub_password_min_length(); ?> characters. Give the user this temporary string, then ask them to use Forgotten Password on first access to set their own password.</p>
        </div>
        <div>
          <label for="user_customer_id">Customer</label>
          <select id="user_customer_id" name="customer_id">
            <option value="">-- None / Admin Only --</option>
            <?php foreach ($customerOptions as $opt): ?>
              <?php
                $label = trim((string) ($opt['name'] ?? ''));
                $code = (string) ($opt['code'] ?? '');
                $label = $label !== '' ? $label . ' (' . $code . ')' : $code;
              ?>
              <option value="<?php echo hub_h((string) $opt['id']); ?>">
                <?php echo hub_h($label); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="user_role">Role</label>
          <select id="user_role" name="role">
            <?php foreach ($assignableRoleOptions as $roleOption): ?>
              <option value="<?php echo hub_h((string) $roleOption['role_key']); ?>"><?php echo hub_h((string) $roleOption['label']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="links" style="justify-content:flex-start;">
          <button type="submit">Add User</button>
        </div>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="modal admin-entity-modal" id="userRequestModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Add New User</h2>
        <button type="button" class="close-btn but2" data-close-modal="userRequestModal" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="/admin/users.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="request_user">
        <div>
          <label for="request_user_email">Email *</label>
          <input id="request_user_email" name="email" type="email" required>
        </div>
        <div>
          <label for="request_user_display_name">Name</label>
          <input id="request_user_display_name" name="display_name" type="text">
        </div>
        <div>
          <label for="request_user_job_title">Title</label>
          <input id="request_user_job_title" name="job_title" type="text">
        </div>
        <div>
          <label>Customer</label>
          <input type="text" value="<?php echo hub_h($scopeCustomerLabel !== '' ? $scopeCustomerLabel : 'Not assigned'); ?>" readonly>
        </div>
        <div>
          <label for="request_user_phone">Telephone</label>
          <input id="request_user_phone" name="phone" type="tel">
        </div>
        <div>
          <label for="request_user_linkedin">LinkedIn URL</label>
          <input id="request_user_linkedin" name="linkedin" type="url" placeholder="https://www.linkedin.com/in/name">
        </div>
        <div>
          <label for="request_user_notes">Notes</label>
          <textarea id="request_user_notes" name="notes" rows="4"></textarea>
        </div>
        <div class="links" style="justify-content:flex-start;">
          <button type="submit">Add User</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <script>
    (function () {
      function openModalById(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
      }
      function closeModalById(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
      }
      document.addEventListener('click', function (event) {
        var copy = event.target.closest('[data-copy-target]');
        if (copy) {
          var copyField = document.getElementById(copy.getAttribute('data-copy-target'));
          if (copyField) {
            var copyText = copyField.value || '';
            if (navigator.clipboard && window.isSecureContext) {
              navigator.clipboard.writeText(copyText).then(function () {
                copy.textContent = 'Copied';
              });
            } else {
              copyField.focus();
              copyField.select();
              document.execCommand('copy');
              copy.textContent = 'Copied';
            }
          }
          return;
        }
        var open = event.target.closest('[data-open-modal]');
        if (open) {
          openModalById(open.getAttribute('data-open-modal'));
          return;
        }
        var close = event.target.closest('[data-close-modal]');
        if (close) {
          closeModalById(close.getAttribute('data-close-modal'));
          return;
        }
        if (event.target.classList && event.target.classList.contains('modal')) {
          event.target.classList.remove('open');
          event.target.setAttribute('aria-hidden', 'true');
        }
      });
      document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.modal.open').forEach(function (modal) {
          modal.classList.remove('open');
          modal.setAttribute('aria-hidden', 'true');
        });
      });
      if (window.location.hash === '#new-user') {
        openModalById(<?php echo json_encode($canManageAllUsers ? 'userAddModal' : 'userRequestModal'); ?>);
      }
    })();
  </script>
  <script src="/js/hub.js?v=20260713-table-filters"></script>
</body>
</html>
