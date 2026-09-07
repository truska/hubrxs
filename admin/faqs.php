<?php
require_once __DIR__ . '/../includes/app/faq.php';
require_once __DIR__ . '/../includes/app/admin_layout.php';
hub_require_super_admin();

global $pdo, $DB_OK;

$error = null;
$messages = hub_flash_messages();

function hub_admin_faq_plain_answer(array $row): string {
  $text = trim(strip_tags((string) ($row['answer_html'] ?? '')));
  return preg_replace('/\s+/', ' ', $text) ?: '';
}

function hub_admin_faq_row_value(array $row, string $column): string {
  if ($column === 'id') {
    return (string) ($row['id'] ?? '');
  }
  if ($column === 'section') {
    return hub_faq_role_label((string) ($row['section'] ?? ''));
  }
  if ($column === 'page_key') {
    return hub_faq_page_label((string) ($row['page_key'] ?? ''));
  }
  if ($column === 'answer') {
    return hub_admin_faq_plain_answer($row);
  }
  if ($column === 'publish') {
    return ((int) ($row['show_on_web'] ?? 0) === 1) ? 'Yes' : 'No';
  }
  return trim((string) ($row[$column] ?? ''));
}

function hub_admin_faq_row_matches_filters(array $row, array $filters, ?string $exceptColumn = null): bool {
  foreach ($filters as $column => $filter) {
    $column = (string) $column;
    if ($exceptColumn !== null && $column === $exceptColumn) {
      continue;
    }
    $filter = trim((string) $filter);
    if ($filter === '') {
      continue;
    }
    $value = hub_admin_faq_row_value($row, $column);
    if (in_array($column, ['section', 'page_key', 'publish'], true)) {
      if ($value !== $filter) {
        return false;
      }
      continue;
    }
    if (mb_strpos(mb_strtolower($value), mb_strtolower($filter)) === false) {
      return false;
    }
  }
  return true;
}

function hub_admin_faq_has_filters(array $filters): bool {
  foreach ($filters as $filter) {
    if (trim((string) $filter) !== '') {
      return true;
    }
  }
  return false;
}

function hub_admin_faq_url(array $params): string {
  $current = [
    'page' => $_GET['page'] ?? 1,
    'per_page' => $_GET['per_page'] ?? 50,
    'col' => $_GET['col'] ?? [],
    'sort' => $_GET['sort'] ?? 'id_asc',
  ];
  return '/admin/faqs.php?' . http_build_query(array_merge($current, $params));
}

function hub_admin_faq_sort_link(string $column, string $label, string $currentSort): string {
  $ascKey = $column . '_asc';
  $descKey = $column . '_desc';
  $next = ($currentSort === $ascKey) ? $descKey : $ascKey;
  $arrow = ' ↕';
  if ($currentSort === $ascKey) {
    $arrow = ' ▲';
  } elseif ($currentSort === $descKey) {
    $arrow = ' ▼';
  }
  return '<a href="' . hub_h(hub_admin_faq_url(['sort' => $next, 'page' => 1])) . '">' . hub_h($label) . $arrow . '</a>';
}

function hub_admin_faq_form_fields(array $faq, string $idPrefix): string {
  ob_start();
  $selectedSection = (string) ($faq['section'] ?? 'user');
  $selectedPage = (string) ($faq['page_key'] ?? '');
  ?>
  <div>
    <label for="<?php echo hub_h($idPrefix); ?>_section">Section / Minimum Role</label>
    <select id="<?php echo hub_h($idPrefix); ?>_section" name="section">
      <?php foreach (hub_faq_role_options() as $role): ?>
        <?php $key = (string) $role['role_key']; ?>
        <option value="<?php echo hub_h($key); ?>" <?php echo $selectedSection === $key ? 'selected' : ''; ?>><?php echo hub_h((string) $role['label']); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="<?php echo hub_h($idPrefix); ?>_page_key">Page / Report</label>
    <select id="<?php echo hub_h($idPrefix); ?>_page_key" name="page_key">
      <?php foreach (hub_faq_page_options() as $key => $label): ?>
        <option value="<?php echo hub_h($key); ?>" <?php echo $selectedPage === (string) $key ? 'selected' : ''; ?>><?php echo hub_h($label); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="<?php echo hub_h($idPrefix); ?>_sort">Sort</label>
    <input id="<?php echo hub_h($idPrefix); ?>_sort" name="sort" type="number" value="<?php echo (int) ($faq['sort'] ?? 100); ?>">
  </div>
  <div>
    <label for="<?php echo hub_h($idPrefix); ?>_question">Question *</label>
    <input id="<?php echo hub_h($idPrefix); ?>_question" name="question" type="text" required value="<?php echo hub_h((string) ($faq['question'] ?? '')); ?>">
  </div>
  <div class="admin-form-wide">
    <label for="<?php echo hub_h($idPrefix); ?>_answer_html">Answer HTML *</label>
    <textarea id="<?php echo hub_h($idPrefix); ?>_answer_html" name="answer_html" rows="9" required><?php echo hub_h((string) ($faq['answer_html'] ?? '')); ?></textarea>
  </div>
  <div class="modal-checkbox-row admin-form-wide">
    <label class="muted"><input type="checkbox" name="show_on_web" value="1" <?php echo ((int) ($faq['show_on_web'] ?? 1) === 1) ? 'checked' : ''; ?>> Publish</label>
    <label class="muted"><input type="checkbox" name="archived" value="1" <?php echo !empty($faq['archived']) ? 'checked' : ''; ?>> Archived</label>
  </div>
  <?php
  return (string) ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add_faq', 'update_faq'], true)) {
  if (!hub_verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please try again.';
  } elseif (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_faq')) {
    $error = 'FAQ table is not available.';
  } else {
    $action = (string) $_POST['action'];
    $faqId = (int) ($_POST['id'] ?? 0);
    $section = hub_valid_user_role_key(trim((string) ($_POST['section'] ?? 'user')));
    $pageKey = hub_faq_normalise_page_key((string) ($_POST['page_key'] ?? ''));
    $question = trim((string) ($_POST['question'] ?? ''));
    $answerHtml = hub_content_sanitize_html((string) ($_POST['answer_html'] ?? ''));
    $sortValue = (int) ($_POST['sort'] ?? 100);
    $showOnWeb = !empty($_POST['show_on_web']) ? 1 : 0;
    $archived = !empty($_POST['archived']) ? 1 : 0;

    if ($question === '' || $answerHtml === '') {
      $error = 'Question and answer are required.';
    } elseif ($action === 'update_faq' && $faqId <= 0) {
      $error = 'FAQ record not found.';
    } else {
      try {
        if ($action === 'update_faq') {
          $stmt = $pdo->prepare(
            'UPDATE hub_faq
             SET section = :section, page_key = :page_key, question = :question, answer_html = :answer_html, sort = :sort, show_on_web = :show_on_web, archived = :archived, modified = NOW()
             WHERE id = :id
             LIMIT 1'
          );
          $stmt->execute([
            ':id' => $faqId,
            ':section' => $section,
            ':page_key' => $pageKey !== '' ? $pageKey : null,
            ':question' => $question,
            ':answer_html' => $answerHtml,
            ':sort' => $sortValue,
            ':show_on_web' => $showOnWeb,
            ':archived' => $archived,
          ]);
          hub_flash('success', 'FAQ updated.');
        } else {
          $stmt = $pdo->prepare(
            'INSERT INTO hub_faq (section, page_key, question, answer_html, sort, show_on_web, archived, created, modified)
             VALUES (:section, :page_key, :question, :answer_html, :sort, :show_on_web, :archived, NOW(), NOW())'
          );
          $stmt->execute([
            ':section' => $section,
            ':page_key' => $pageKey !== '' ? $pageKey : null,
            ':question' => $question,
            ':answer_html' => $answerHtml,
            ':sort' => $sortValue,
            ':show_on_web' => $showOnWeb,
            ':archived' => $archived,
          ]);
          hub_flash('success', 'FAQ added.');
        }
        hub_redirect('/admin/faqs.php');
      } catch (PDOException $e) {
        $error = 'Unable to save FAQ item.';
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
$sort = trim((string) ($_GET['sort'] ?? 'id_asc'));
$allowedSortColumns = array_fill_keys(['id', 'question', 'answer', 'section', 'page_key', 'sort', 'publish'], true);
$totalRows = 0;
$totalPages = 1;
$allFaqs = [];
$faqs = [];
$filterOptions = [
  'section' => [],
  'page_key' => [],
  'publish' => [],
];

if ($DB_OK && ($pdo instanceof PDO) && hub_table_exists('hub_faq')) {
  $stmt = $pdo->query('SELECT * FROM hub_faq ORDER BY archived ASC, section ASC, COALESCE(NULLIF(page_key, ""), "zzzz_general") ASC, sort ASC, question ASC, id ASC LIMIT 5000');
  $allFaqs = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

  foreach (array_keys($filterOptions) as $field) {
    foreach ($allFaqs as $row) {
      if (!hub_admin_faq_row_matches_filters($row, $columnFilters, $field)) {
        continue;
      }
      $value = hub_admin_faq_row_value($row, $field);
      if ($value !== '') {
        $filterOptions[$field][$value] = true;
      }
    }
    ksort($filterOptions[$field], SORT_NATURAL | SORT_FLAG_CASE);
  }

  $filteredFaqs = [];
  foreach ($allFaqs as $row) {
    if (hub_admin_faq_row_matches_filters($row, $columnFilters)) {
      $filteredFaqs[] = $row;
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
  usort($filteredFaqs, static function (array $left, array $right) use ($sortColumn, $sortDirection): int {
    $leftValue = hub_admin_faq_row_value($left, $sortColumn);
    $rightValue = hub_admin_faq_row_value($right, $sortColumn);
    if (is_numeric($leftValue) && is_numeric($rightValue)) {
      $result = ((float) $leftValue) <=> ((float) $rightValue);
    } else {
      $result = strnatcasecmp((string) $leftValue, (string) $rightValue);
    }
    return $sortDirection === 'desc' ? -$result : $result;
  });

  $totalRows = count($filteredFaqs);
  $totalPages = max(1, (int) ceil($totalRows / $perPage));
  $page = min($page, $totalPages);
  $offset = ($page - 1) * $perPage;
  $faqs = array_slice($filteredFaqs, $offset, $perPage);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo hub_h(HUB_APP_NAME); ?> | FAQs</title>
  <link rel="stylesheet" href="/css/hub.css">
</head>
<body class="admin-page faq-admin-page">
  <?php echo hub_admin_header('super'); ?>
  <div class="stack">
    <div class="wrap">
      <div class="card">
        <div class="flex">
          <div>
            <p class="brand">Super</p>
            <h1>FAQs</h1>
            <p class="muted">Manage role-aware manual and FAQ content.</p>
          </div>
          <div class="links">
            <a href="/faq.php">View FAQs</a>
            <a href="/super.php">Back To Super</a>
            <button type="button" data-open-modal="faqAddModal">Add New FAQ</button>
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
        <h2>Existing FAQs</h2>
        <form method="get" action="/admin/faqs.php" class="import-data-controls admin-table-controls" id="faqs-filter-form">
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
          <?php if (hub_admin_faq_has_filters($columnFilters)): ?>
            <div class="links import-data-reset">
              <a href="/admin/faqs.php?per_page=<?php echo (int) $perPage; ?>&sort=<?php echo urlencode($sort); ?>">Clear Filters</a>
            </div>
          <?php endif; ?>
        </form>

        <?php if (empty($allFaqs)): ?>
          <div class="alert info">No FAQs Found.</div>
        <?php else: ?>
          <div class="import-data-table-wrap">
            <table class="table faqs-table import-data-table">
              <thead>
                <tr>
                  <th><?php echo hub_admin_faq_sort_link('id', 'ID', $sort); ?></th>
                  <th><?php echo hub_admin_faq_sort_link('question', 'Question', $sort); ?></th>
                  <th><?php echo hub_admin_faq_sort_link('answer', 'Answer', $sort); ?></th>
                  <th><?php echo hub_admin_faq_sort_link('section', 'Section', $sort); ?></th>
                  <th><?php echo hub_admin_faq_sort_link('page_key', 'Page', $sort); ?></th>
                  <th><?php echo hub_admin_faq_sort_link('sort', 'Sort', $sort); ?></th>
                  <th><?php echo hub_admin_faq_sort_link('publish', 'Publish', $sort); ?></th>
                  <th class="table-actions-heading">Action</th>
                </tr>
                <tr class="import-data-filter-row">
                  <th><input name="col[id]" form="faqs-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['id'] ?? '')); ?>" placeholder="Search"></th>
                  <th><input name="col[question]" form="faqs-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['question'] ?? '')); ?>" placeholder="Search"></th>
                  <th></th>
                  <th>
                    <select name="col[section]" form="faqs-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (array_keys($filterOptions['section']) as $option): ?>
                        <option value="<?php echo hub_h($option); ?>" <?php echo (($columnFilters['section'] ?? '') === $option) ? 'selected' : ''; ?>><?php echo hub_h($option); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th>
                    <select name="col[page_key]" form="faqs-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (array_keys($filterOptions['page_key']) as $option): ?>
                        <option value="<?php echo hub_h($option); ?>" <?php echo (($columnFilters['page_key'] ?? '') === $option) ? 'selected' : ''; ?>><?php echo hub_h($option); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th><input name="col[sort]" form="faqs-filter-form" type="search" data-auto-filter value="<?php echo hub_h((string) ($columnFilters['sort'] ?? '')); ?>" placeholder="Search"></th>
                  <th>
                    <select name="col[publish]" form="faqs-filter-form" data-auto-filter>
                      <option value="">All</option>
                      <?php foreach (array_keys($filterOptions['publish']) as $option): ?>
                        <option value="<?php echo hub_h($option); ?>" <?php echo (($columnFilters['publish'] ?? '') === $option) ? 'selected' : ''; ?>><?php echo hub_h($option); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </th>
                  <th class="table-actions-heading"><i class="fa-solid fa-pencil" aria-hidden="true"></i></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($faqs)): ?>
                  <tr><td colspan="8" class="muted">No FAQs Found.</td></tr>
                <?php else: ?>
                  <?php foreach ($faqs as $faq): ?>
                    <?php
                      $modalId = 'faqEditModal' . (int) $faq['id'];
                      $answer = hub_admin_faq_plain_answer($faq);
                    ?>
                    <tr>
                      <td><?php echo (int) $faq['id']; ?></td>
                      <td><?php echo hub_h((string) $faq['question']); ?></td>
                      <td class="faq-answer-preview" title="<?php echo hub_h($answer); ?>"><?php echo hub_h($answer); ?></td>
                      <td><?php echo hub_h(hub_faq_role_label((string) $faq['section'])); ?></td>
                      <td><?php echo hub_h(hub_faq_page_label((string) ($faq['page_key'] ?? ''))); ?></td>
                      <td><?php echo (int) $faq['sort']; ?></td>
                      <td><?php echo !empty($faq['show_on_web']) ? 'Yes' : 'No'; ?></td>
                      <td class="table-actions"><button type="button" class="icon-action" data-open-modal="<?php echo hub_h($modalId); ?>" title="Edit <?php echo hub_h((string) $faq['question']); ?>" aria-label="Edit <?php echo hub_h((string) $faq['question']); ?>"><i class="fa-solid fa-pencil" aria-hidden="true"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php foreach ($faqs as $faq): ?>
            <?php $modalId = 'faqEditModal' . (int) $faq['id']; ?>
            <div class="modal admin-entity-modal" id="<?php echo hub_h($modalId); ?>" aria-hidden="true">
              <div class="modal-content">
                <div class="modal-header">
                  <h2>Edit FAQ</h2>
                  <button type="button" class="close-btn but2" data-close-modal="<?php echo hub_h($modalId); ?>" aria-label="Close">&times;</button>
                </div>
                <form method="post" action="/admin/faqs.php">
                  <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
                  <input type="hidden" name="action" value="update_faq">
                  <input type="hidden" name="id" value="<?php echo (int) $faq['id']; ?>">
                  <?php echo hub_admin_faq_form_fields($faq, 'edit_faq_' . (int) $faq['id']); ?>
                  <div class="links admin-page-actions"><button type="submit">Save FAQ</button></div>
                </form>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if ($totalRows > 0): ?>
            <nav class="import-data-pagination" aria-label="FAQ pages">
              <div class="links">
                <?php if ($page > 1): ?>
                  <a href="<?php echo hub_h(hub_admin_faq_url(['page' => 1])); ?>">First</a>
                  <a href="<?php echo hub_h(hub_admin_faq_url(['page' => $page - 1])); ?>">Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></span>
                <?php if ($page < $totalPages): ?>
                  <a href="<?php echo hub_h(hub_admin_faq_url(['page' => $page + 1])); ?>">Next</a>
                  <a href="<?php echo hub_h(hub_admin_faq_url(['page' => $totalPages])); ?>">Last</a>
                <?php endif; ?>
              </div>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="modal admin-entity-modal" id="faqAddModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Add New FAQ</h2>
        <button type="button" class="close-btn but2" data-close-modal="faqAddModal" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="/admin/faqs.php">
        <input type="hidden" name="csrf" value="<?php echo hub_h(hub_csrf_token()); ?>">
        <input type="hidden" name="action" value="add_faq">
        <?php echo hub_admin_faq_form_fields([], 'faq'); ?>
        <div class="links admin-page-actions"><button type="submit">Add FAQ</button></div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      document.addEventListener('click', function (event) {
        var open = event.target.closest('[data-open-modal]');
        if (open) {
          var modal = document.getElementById(open.getAttribute('data-open-modal'));
          if (modal) {
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
          }
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
