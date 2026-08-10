<?php
require_once __DIR__ . '/../includes/app/admin_layout.php';
require_once __DIR__ . '/../includes/app/dashboard_buttons.php';
hub_require_admin();

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();

function hub_admin_customers_input(string $key): string {
  return trim((string) ($_POST[$key] ?? ''));
}

function hub_admin_customers_nullable(string $value): ?string {
  $value = trim($value);
  return $value !== '' ? $value : null;
}

function hub_admin_customers_key_contact_valid(int $customerId, ?int $userId): bool {
  global $pdo, $DB_OK;

  if ($userId === null || $userId <= 0) {
    return true;
  }
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_user')) {
    return false;
  }

  $stmt = $pdo->prepare('SELECT id FROM hub_user WHERE id = :id AND customer_id = :customer_id AND archived = 0 LIMIT 1');
  $stmt->execute([
    ':id' => $userId,
    ':customer_id' => $customerId,
  ]);
  return (bool) $stmt->fetchColumn();
}

function hub_admin_customers_address(array $row): string {
  $parts = [
    $row['address_line1'] ?? '',
    $row['address_line2'] ?? '',
    $row['address_line3'] ?? '',
    $row['address_city'] ?? '',
    $row['address_county'] ?? '',
    $row['address_postcode'] ?? '',
    $row['address_country'] ?? '',
  ];
  $parts = array_values(array_filter(array_map(static function ($value): string {
    return trim((string) $value);
  }, $parts)));
  return implode(', ', $parts);
}

function hub_admin_customers_row_value(array $row, string $column): string {
  if ($column === 'id') {
    return (string) ($row['id'] ?? '');
  }
  if ($column === 'email') {
    return trim((string) ($row['contact_email'] ?? ''));
  }
  if ($column === 'phone') {
    return trim((string) ($row['contact_phone'] ?? ''));
  }
  if ($column === 'city') {
    return trim((string) ($row['address_city'] ?? ''));
  }
  if ($column === 'country') {
    return trim((string) ($row['address_country'] ?? ''));
  }
  if ($column === 'key_contact') {
    return trim((string) (($row['key_contact_name'] ?? '') ?: ($row['key_contact_email'] ?? '')));
  }
  if ($column === 'customer_type') {
    $type = hub_valid_customer_type((string) ($row['customer_type'] ?? 'customer'));
    return hub_customer_type_options()[$type] ?? 'Customer';
  }
  return trim((string) ($row[$column] ?? ''));
}

function hub_admin_customers_row_matches_filters(array $row, array $filters): bool {
  foreach ($filters as $column => $filter) {
    $column = (string) $column;
    $filter = trim((string) $filter);
    if ($filter === '') {
      continue;
    }
    if (!in_array($column, ['id', 'name', 'email', 'phone', 'city', 'country', 'key_contact'], true)) {
      if ($column !== 'customer_type') {
        continue;
      }
    }
    if ($column === 'customer_type') {
      $rowType = hub_valid_customer_type((string) ($row['customer_type'] ?? 'customer'));
      if ($rowType !== hub_valid_customer_type($filter)) {
        return false;
      }
      continue;
    }
    $value = hub_admin_customers_row_value($row, $column);
    if (mb_strpos(mb_strtolower($value), mb_strtolower($filter)) === false) {
      return false;
    }
  }
  return true;
}

function hub_admin_customers_has_filters(array $filters): bool {
  foreach ($filters as $filter) {
    if (trim((string) $filter) !== '') {
      return true;
    }
  }
  return false;
}

function hub_admin_customers_url(array $params): string {
  $current = [
    'page' => $_GET['page'] ?? 1,
    'per_page' => $_GET['per_page'] ?? 50,
    'col' => $_GET['col'] ?? [],
    'sort' => $_GET['sort'] ?? 'name_asc',
  ];
  return '/admin/customers.php?' . http_build_query(array_merge($current, $params));
}

function hub_admin_customers_sort_link(string $column, string $label, string $currentSort): string {
  $ascKey = $column . '_asc';
  $descKey = $column . '_desc';
  $next = ($currentSort === $ascKey) ? $descKey : $ascKey;
  $arrow = ' ↕';
  if ($currentSort === $ascKey) {
    $arrow = ' ▲';
  } elseif ($currentSort === $descKey) {
    $arrow = ' ▼';
  }
  return '<a href="' . hub_h(hub_admin_customers_url(['sort' => $next, 'page' => 1])) . '">' . hub_h($label) . $arrow . '</a>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add_customer', 'update_customer'], true)) {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_customer')) {
    $error = 'Database not available.';
  } else {
    $action = (string) $_POST['action'];
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $code = hub_admin_customers_input('code');
    $name = hub_admin_customers_input('name');
    $email = hub_admin_customers_input('contact_email');
    $phone = hub_admin_customers_input('contact_phone');
    $addressLine1 = hub_admin_customers_input('address_line1');
    $addressLine2 = hub_admin_customers_input('address_line2');
    $addressLine3 = hub_admin_customers_input('address_line3');
    $addressCity = hub_admin_customers_input('address_city');
    $addressCounty = hub_admin_customers_input('address_county');
    $addressPostcode = hub_admin_customers_input('address_postcode');
    $addressCountry = hub_admin_customers_input('address_country');
    $keyContactUserId = trim((string) ($_POST['key_contact_user_id'] ?? '')) === '' ? null : (int) $_POST['key_contact_user_id'];
    $customerType = hub_valid_customer_type((string) ($_POST['customer_type'] ?? 'customer'));

    if ($code === '' || $name === '') {
      $error = 'Code and name are required.';
    } elseif ($action === 'update_customer' && $customerId <= 0) {
      $error = 'Customer record not found.';
    } elseif ($action === 'update_customer' && !hub_admin_customers_key_contact_valid($customerId, $keyContactUserId)) {
      $error = 'Key contact must be a user assigned to this customer.';
    } else {
      try {
        $params = [
          ':code' => $code,
          ':name' => $name,
          ':email' => hub_admin_customers_nullable($email),
          ':phone' => hub_admin_customers_nullable($phone),
          ':address_line1' => hub_admin_customers_nullable($addressLine1),
          ':address_line2' => hub_admin_customers_nullable($addressLine2),
          ':address_line3' => hub_admin_customers_nullable($addressLine3),
          ':address_city' => hub_admin_customers_nullable($addressCity),
          ':address_county' => hub_admin_customers_nullable($addressCounty),
          ':address_postcode' => hub_admin_customers_nullable($addressPostcode),
          ':address_country' => hub_admin_customers_nullable($addressCountry),
          ':key_contact_user_id' => $action === 'update_customer' ? $keyContactUserId : null,
          ':customer_type' => $customerType,
        ];

        if ($action === 'add_customer') {
          $stmt = $pdo->prepare(
            'INSERT INTO hub_customer
             (code, name, contact_email, contact_phone, address_line1, address_line2, address_line3, address_city, address_county, address_postcode, address_country, key_contact_user_id, customer_type, archived, created, modified)
             VALUES
             (:code, :name, :email, :phone, :address_line1, :address_line2, :address_line3, :address_city, :address_county, :address_postcode, :address_country, :key_contact_user_id, :customer_type, 0, NOW(), NOW())'
          );
          $stmt->execute($params);
          $customerId = (int) $pdo->lastInsertId();
          hub_log_user_action([
            'user' => hub_current_user(),
            'action_key' => 'customer_added',
            'action_title' => 'Customer added',
            'table_name' => 'hub_customer',
            'record_id' => $customerId,
            'sql_text' => 'INSERT INTO hub_customer (...)',
            'details' => ['code' => $code, 'name' => $name, 'customer_type' => $customerType, 'key_contact_user_id' => $keyContactUserId],
          ]);
          hub_flash('success', 'Customer added.');
        } else {
          $params[':id'] = $customerId;
          $stmt = $pdo->prepare(
            'UPDATE hub_customer
             SET code = :code,
                 name = :name,
                 contact_email = :email,
                 contact_phone = :phone,
                 address_line1 = :address_line1,
                 address_line2 = :address_line2,
                 address_line3 = :address_line3,
                 address_city = :address_city,
                 address_county = :address_county,
                 address_postcode = :address_postcode,
                 address_country = :address_country,
                 key_contact_user_id = :key_contact_user_id,
                 customer_type = :customer_type,
                 modified = NOW()
             WHERE id = :id
             LIMIT 1'
          );
          $stmt->execute($params);
          hub_log_user_action([
            'user' => hub_current_user(),
            'action_key' => 'customer_updated',
            'action_title' => 'Customer updated',
            'table_name' => 'hub_customer',
            'record_id' => $customerId,
            'sql_text' => 'UPDATE hub_customer SET code = :code, name = :name, contact_email = :email, contact_phone = :phone, address_line1 = :address_line1, address_line2 = :address_line2, address_line3 = :address_line3, address_city = :address_city, address_county = :address_county, address_postcode = :address_postcode, address_country = :address_country, key_contact_user_id = :key_contact_user_id, customer_type = :customer_type, modified = NOW() WHERE id = :id LIMIT 1',
            'sql_params' => $params,
            'details' => ['code' => $code, 'name' => $name, 'customer_type' => $customerType, 'key_contact_user_id' => $keyContactUserId],
          ]);
          hub_flash('success', 'Customer updated.');
        }
        hub_redirect('/admin/customers.php');
      } catch (PDOException $e) {
        $error = 'Unable to save customer. Code or name may already be in use.';
      }
    }
  }
}

$columnFilters = is_array($_GET['col'] ?? null) ? $_GET['col'] : [];
$columnFilters = array_map(static function ($value): string {
  return trim((string) $value);
}, $columnFilters);
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min(200, $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = trim((string) ($_GET['sort'] ?? 'name_asc'));
$allowedSortColumns = array_fill_keys(['id', 'name', 'customer_type', 'email', 'phone', 'city', 'country', 'key_contact'], true);
$totalRows = 0;
$totalPages = 1;
$allCustomers = [];
$customers = [];
$usersByCustomer = [];

if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_user')) {
  $stmtUsers = $pdo->query(
    'SELECT id, customer_id, email, display_name
     FROM hub_user
     WHERE archived = 0 AND customer_id IS NOT NULL
     ORDER BY display_name ASC, email ASC'
  );
  foreach (($stmtUsers ? $stmtUsers->fetchAll(PDO::FETCH_ASSOC) : []) as $userRow) {
    $cid = (int) ($userRow['customer_id'] ?? 0);
    if ($cid > 0) {
      $usersByCustomer[$cid][] = $userRow;
    }
  }
}

if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_customer')) {
  $stmt = $pdo->query(
    'SELECT c.id, c.code, c.name, c.customer_type, c.contact_email, c.contact_phone,
            c.address_line1, c.address_line2, c.address_line3, c.address_city, c.address_county, c.address_postcode, c.address_country,
            c.key_contact_user_id, c.archived, c.created,
            u.display_name AS key_contact_name, u.email AS key_contact_email
     FROM hub_customer c
     LEFT JOIN hub_user u ON u.id = c.key_contact_user_id
     ORDER BY c.name ASC, c.id ASC
     LIMIT 5000'
  );
  $allCustomers = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

  $filteredCustomers = [];
  foreach ($allCustomers as $row) {
    if (hub_admin_customers_row_matches_filters($row, $columnFilters)) {
      $filteredCustomers[] = $row;
    }
  }

  $sortParts = explode('_', $sort);
  $sortDirection = array_pop($sortParts);
  $sortColumn = implode('_', $sortParts);
  if (!isset($allowedSortColumns[$sortColumn]) || !in_array($sortDirection, ['asc', 'desc'], true)) {
    $sortColumn = 'name';
    $sortDirection = 'asc';
    $sort = 'name_asc';
  }
  usort($filteredCustomers, static function (array $left, array $right) use ($sortColumn, $sortDirection): int {
    $leftValue = hub_admin_customers_row_value($left, $sortColumn);
    $rightValue = hub_admin_customers_row_value($right, $sortColumn);
    if (is_numeric($leftValue) && is_numeric($rightValue)) {
      $result = ((float) $leftValue) <=> ((float) $rightValue);
    } else {
      $result = strnatcasecmp((string) $leftValue, (string) $rightValue);
    }
    return $sortDirection === 'desc' ? -$result : $result;
  });

  $totalRows = count($filteredCustomers);
  $totalPages = max(1, (int) ceil($totalRows / $perPage));
  $page = min($page, $totalPages);
  $offset = ($page - 1) * $perPage;
  $customers = array_slice($filteredCustomers, $offset, $perPage);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | Admin Customers</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page customers-page">
  <?php echo hub_admin_header('admin'); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Admin</p>
            <h1>Customers</h1>
            <p class="muted">Add or review customers for user assignment and data visibility.</p>
          </div>
          <div class="links">
            <a href="/admin.php">Back to Admin</a>
            <a href="/dashboard.php">Dashboard</a>
            <button type="button" data-open-modal="customerAddModal">Add New Customer</button>
          </div>
        </div>

        <?php foreach ($messages as $msg): ?>
          <div class="alert <?php echo hub_h($msg['type']); ?>"><?php echo hub_h($msg['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
          <div class="alert error"><?php echo hub_h($error); ?></div>
        <?php endif; ?>
      </div>

      <div class="card">
        <p class="brand">List</p>
        <h2>Existing Customers</h2>
        <form method="get" action="/admin/customers.php" class="import-data-controls admin-table-controls" id="customers-filter-form">
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
          <?php if (hub_admin_customers_has_filters($columnFilters)): ?>
            <div class="links import-data-reset">
              <a href="/admin/customers.php?per_page=<?php echo (int) $perPage; ?>&sort=<?php echo urlencode($sort); ?>">Clear Filters</a>
            </div>
          <?php endif; ?>
        </form>

        <?php if (empty($allCustomers)): ?>
          <div class="alert info">No Customers Found.</div>
        <?php else: ?>
          <div class="import-data-table-wrap">
            <table class="table customers-table import-data-table">
              <thead>
                <tr>
                  <th><?php echo hub_admin_customers_sort_link('id', 'ID', $sort); ?></th>
                  <th><?php echo hub_admin_customers_sort_link('name', 'Name', $sort); ?></th>
                  <th><?php echo hub_admin_customers_sort_link('customer_type', 'Type', $sort); ?></th>
                  <th><?php echo hub_admin_customers_sort_link('email', 'Email', $sort); ?></th>
                  <th><?php echo hub_admin_customers_sort_link('phone', 'Phone', $sort); ?></th>
                  <th><?php echo hub_admin_customers_sort_link('city', 'City', $sort); ?></th>
                  <th><?php echo hub_admin_customers_sort_link('country', 'Country', $sort); ?></th>
                  <th><?php echo hub_admin_customers_sort_link('key_contact', 'Key Contact', $sort); ?></th>
                  <th class="table-actions-heading">Action</th>
                </tr>
                <tr class="import-data-filter-row">
                  <th><input name="col[id]" form="customers-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['id'] ?? '')); ?>" placeholder="Search"></th>
                  <th><input name="col[name]" form="customers-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['name'] ?? '')); ?>" placeholder="Search"></th>
                  <th>
                    <select name="col[customer_type]" form="customers-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (hub_customer_type_options() as $typeKey => $typeLabel): ?>
                        <option value="<?php echo hub_h($typeKey); ?>" <?php echo (($columnFilters['customer_type'] ?? '') === $typeKey) ? 'selected' : ''; ?>><?php echo hub_h($typeLabel); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th><input name="col[email]" form="customers-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['email'] ?? '')); ?>" placeholder="Search"></th>
                  <th><input name="col[phone]" form="customers-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['phone'] ?? '')); ?>" placeholder="Search"></th>
                  <th><input name="col[city]" form="customers-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['city'] ?? '')); ?>" placeholder="Search"></th>
                  <th><input name="col[country]" form="customers-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['country'] ?? '')); ?>" placeholder="Search"></th>
                  <th><input name="col[key_contact]" form="customers-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['key_contact'] ?? '')); ?>" placeholder="Search"></th>
                  <th class="table-actions-heading"><i class="fa-solid fa-pencil" aria-hidden="true"></i></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($customers)): ?>
                  <tr><td colspan="9" class="muted">No Customers Found.</td></tr>
                <?php else: ?>
                  <?php foreach ($customers as $cust): ?>
                    <?php
                      $modalId = 'customerEditModal' . (int) $cust['id'];
                      $keyContact = trim((string) (($cust['key_contact_name'] ?? '') ?: ($cust['key_contact_email'] ?? '')));
                    ?>
                    <tr>
                      <td><?php echo hub_h((string) $cust['id']); ?></td>
                      <td><?php echo hub_h((string) $cust['name']); ?></td>
                      <td><?php echo hub_h(hub_admin_customers_row_value($cust, 'customer_type')); ?></td>
                      <td>
                        <?php if (!empty($cust['contact_email'])): ?>
                          <a href="mailto:<?php echo hub_h((string) $cust['contact_email']); ?>"><?php echo hub_h((string) $cust['contact_email']); ?></a>
                        <?php else: ?>
                          <span class="muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td><?php echo hub_h((string) ($cust['contact_phone'] ?? '')); ?></td>
                      <td><?php echo trim((string) ($cust['address_city'] ?? '')) !== '' ? hub_h((string) $cust['address_city']) : '<span class="muted">-</span>'; ?></td>
                      <td><?php echo trim((string) ($cust['address_country'] ?? '')) !== '' ? hub_h((string) $cust['address_country']) : '<span class="muted">-</span>'; ?></td>
                      <td><?php echo $keyContact !== '' ? hub_h($keyContact) : '<span class="muted">-</span>'; ?></td>
                      <td class="table-actions"><button type="button" class="icon-action" data-open-modal="<?php echo hub_h($modalId); ?>" title="Edit <?php echo hub_h((string) $cust['name']); ?>" aria-label="Edit <?php echo hub_h((string) $cust['name']); ?>"><i class="fa-solid fa-pencil" aria-hidden="true"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalRows > 0): ?>
            <nav class="import-data-pagination" aria-label="Customer pages">
              <div class="links">
                <?php if ($page > 1): ?>
                  <a href="<?php echo hub_h(hub_admin_customers_url(['page' => 1])); ?>">First</a>
                  <a href="<?php echo hub_h(hub_admin_customers_url(['page' => $page - 1])); ?>">Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></span>
                <?php if ($page < $totalPages): ?>
                  <a href="<?php echo hub_h(hub_admin_customers_url(['page' => $page + 1])); ?>">Next</a>
                  <a href="<?php echo hub_h(hub_admin_customers_url(['page' => $totalPages])); ?>">Last</a>
                <?php endif; ?>
              </div>
            </nav>
          <?php endif; ?>

          <?php foreach ($customers as $cust): ?>
            <?php $modalId = 'customerEditModal' . (int) $cust['id']; ?>
            <div class="modal admin-entity-modal" id="<?php echo hub_h($modalId); ?>" aria-hidden="true">
              <div class="modal-content">
                <div class="modal-header">
                  <h2>Edit Customer</h2>
                  <button type="button" class="close-btn but2" data-close-modal="<?php echo hub_h($modalId); ?>" aria-label="Close">&times;</button>
                </div>
                <form method="post" action="/admin/customers.php">
                  <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                  <input type="hidden" name="action" value="update_customer">
                  <input type="hidden" name="customer_id" value="<?php echo (int) $cust['id']; ?>">
                  <?php echo hub_admin_customer_form_fields($cust, $usersByCustomer[(int) $cust['id']] ?? [], true); ?>
                  <div class="links admin-page-actions"><button type="submit">Save Customer</button></div>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="modal admin-entity-modal" id="customerAddModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Add New Customer</h2>
        <button type="button" class="close-btn but2" data-close-modal="customerAddModal" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="/admin/customers.php">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="add_customer">
        <?php echo hub_admin_customer_form_fields([], [], false); ?>
        <div class="links admin-page-actions"><button type="submit">Add Customer</button></div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      function openModalById(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
      }
      document.addEventListener('click', function (event) {
        var open = event.target.closest('[data-open-modal]');
        if (open) {
          openModalById(open.getAttribute('data-open-modal'));
          return;
        }
        var close = event.target.closest('[data-close-modal]');
        if (close) {
          var closeModal = document.getElementById(close.getAttribute('data-close-modal'));
          if (closeModal) {
            closeModal.classList.remove('open');
            closeModal.setAttribute('aria-hidden', 'true');
          }
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
    })();
  </script>
  <script src="/js/hub.js?v=20260713-table-filters"></script>
</body>
</html>
<?php
function hub_admin_customer_form_fields(array $customer, array $customerUsers, bool $isEdit): string {
  ob_start();
  $idSuffix = $isEdit ? '_' . (int) ($customer['id'] ?? 0) : '';
  $selectedKeyContactId = (int) ($customer['key_contact_user_id'] ?? 0);
  ?>
  <div>
    <label for="customer_code<?php echo $idSuffix; ?>">ID *</label>
    <input id="customer_code<?php echo $idSuffix; ?>" name="code" type="text" value="<?php echo hub_h((string) ($customer['code'] ?? '')); ?>" required>
  </div>
  <div>
    <label for="customer_name<?php echo $idSuffix; ?>">Name *</label>
    <input id="customer_name<?php echo $idSuffix; ?>" name="name" type="text" value="<?php echo hub_h((string) ($customer['name'] ?? '')); ?>" required>
  </div>
  <div>
    <label for="customer_type<?php echo $idSuffix; ?>">Customer / Prospect</label>
    <select id="customer_type<?php echo $idSuffix; ?>" name="customer_type">
      <?php $selectedCustomerType = hub_valid_customer_type((string) ($customer['customer_type'] ?? 'customer')); ?>
      <?php foreach (hub_customer_type_options() as $typeKey => $typeLabel): ?>
        <option value="<?php echo hub_h($typeKey); ?>" <?php echo $selectedCustomerType === $typeKey ? 'selected' : ''; ?>><?php echo hub_h($typeLabel); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="customer_contact_email<?php echo $idSuffix; ?>">Contact Email</label>
    <input id="customer_contact_email<?php echo $idSuffix; ?>" name="contact_email" type="email" value="<?php echo hub_h((string) ($customer['contact_email'] ?? '')); ?>" placeholder="Optional">
  </div>
  <div>
    <label for="customer_contact_phone<?php echo $idSuffix; ?>">Contact Phone</label>
    <input id="customer_contact_phone<?php echo $idSuffix; ?>" name="contact_phone" type="tel" value="<?php echo hub_h((string) ($customer['contact_phone'] ?? '')); ?>" placeholder="Optional">
  </div>
  <div>
    <label for="customer_address_line1<?php echo $idSuffix; ?>">Address Line 1</label>
    <input id="customer_address_line1<?php echo $idSuffix; ?>" name="address_line1" type="text" value="<?php echo hub_h((string) ($customer['address_line1'] ?? '')); ?>">
  </div>
  <div>
    <label for="customer_address_line2<?php echo $idSuffix; ?>">Address Line 2</label>
    <input id="customer_address_line2<?php echo $idSuffix; ?>" name="address_line2" type="text" value="<?php echo hub_h((string) ($customer['address_line2'] ?? '')); ?>">
  </div>
  <div>
    <label for="customer_address_line3<?php echo $idSuffix; ?>">Address Line 3</label>
    <input id="customer_address_line3<?php echo $idSuffix; ?>" name="address_line3" type="text" value="<?php echo hub_h((string) ($customer['address_line3'] ?? '')); ?>">
  </div>
  <div>
    <label for="customer_address_city<?php echo $idSuffix; ?>">City</label>
    <input id="customer_address_city<?php echo $idSuffix; ?>" name="address_city" type="text" value="<?php echo hub_h((string) ($customer['address_city'] ?? '')); ?>">
  </div>
  <div>
    <label for="customer_address_county<?php echo $idSuffix; ?>">County/State</label>
    <input id="customer_address_county<?php echo $idSuffix; ?>" name="address_county" type="text" value="<?php echo hub_h((string) ($customer['address_county'] ?? '')); ?>">
  </div>
  <div>
    <label for="customer_address_postcode<?php echo $idSuffix; ?>">Postcode/ZIP</label>
    <input id="customer_address_postcode<?php echo $idSuffix; ?>" name="address_postcode" type="text" value="<?php echo hub_h((string) ($customer['address_postcode'] ?? '')); ?>">
  </div>
  <div>
    <label for="customer_address_country<?php echo $idSuffix; ?>">Country</label>
    <input id="customer_address_country<?php echo $idSuffix; ?>" name="address_country" type="text" value="<?php echo hub_h((string) ($customer['address_country'] ?? '')); ?>">
  </div>
  <div>
    <label for="customer_key_contact<?php echo $idSuffix; ?>">Key Contact</label>
    <select id="customer_key_contact<?php echo $idSuffix; ?>" name="key_contact_user_id" <?php echo $isEdit ? '' : 'disabled'; ?>>
      <option value="">-- None --</option>
      <?php foreach ($customerUsers as $userRow): ?>
        <?php
          $userId = (int) ($userRow['id'] ?? 0);
          $label = trim((string) ($userRow['display_name'] ?? ''));
          $email = trim((string) ($userRow['email'] ?? ''));
          $label = $label !== '' ? $label . ' (' . $email . ')' : $email;
        ?>
        <option value="<?php echo $userId; ?>" <?php echo $selectedKeyContactId === $userId ? 'selected' : ''; ?>><?php echo hub_h($label); ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$isEdit): ?>
      <p class="muted">Key contact can be selected after users are assigned to this customer.</p>
    <?php endif; ?>
  </div>
  <?php
  return (string) ob_get_clean();
}
