<?php
require_once __DIR__ . '/auth.php';

function hub_help_message_by_name(string $name): ?array {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_table_exists('hub_help_message')) {
    return null;
  }

  $stmt = $pdo->prepare(
    'SELECT id, name, heading, context, icon, message_html
     FROM hub_help_message
     WHERE name = :name AND show_on_web = 1 AND archived = 0
     LIMIT 1'
  );
  $stmt->execute([':name' => $name]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function hub_help_button(string $name, string $label = 'Help'): string {
  $message = hub_help_message_by_name($name);
  if (!$message) {
    return '';
  }

  $heading = trim((string) ($message['heading'] ?? ''));
  if ($heading === '') {
    $heading = 'Help';
  }
  $context = hub_help_context((string) ($message['context'] ?? 'info'));
  $iconClass = hub_help_icon_class($context, (string) ($message['icon'] ?? ''));

  return sprintf(
    '<button type="button" class="help-trigger help-trigger-%s" aria-label="%s" title="%s" data-help-id="%d" data-help-name="%s" data-help-heading="%s" data-help-context="%s" data-help-icon-class="%s" data-help-message="%s"><i class="%s" aria-hidden="true"></i></button>',
    hub_h($context),
    hub_h($label),
    hub_h($label),
    (int) $message['id'],
    hub_h((string) $message['name']),
    hub_h($heading),
    hub_h($context),
    hub_h($iconClass),
    hub_h((string) $message['message_html']),
    hub_h($iconClass)
  );
}

function hub_help_context(string $context): string {
  $context = strtolower(trim($context));
  $allowed = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
  return in_array($context, $allowed, true) ? $context : 'info';
}

function hub_help_icon(string $icon): string {
  $icon = strtolower(trim($icon));
  $allowed = [
    'fa-solid fa-circle-info',
    'fa-solid fa-circle-question',
    'fa-solid fa-circle-check',
    'fa-solid fa-circle-exclamation',
    'fa-solid fa-triangle-exclamation',
  ];
  return in_array($icon, $allowed, true) ? $icon : 'fa-solid fa-circle-info';
}

function hub_help_icon_class(string $context, string $storedIcon = ''): string {
  $storedIcon = trim($storedIcon);
  if (str_starts_with($storedIcon, 'fa-')) {
    return $storedIcon;
  }

  $map = [
    'primary' => 'fa-solid fa-circle-info',
    'secondary' => 'fa-solid fa-circle-question',
    'success' => 'fa-solid fa-circle-check',
    'danger' => 'fa-solid fa-circle-exclamation',
    'warning' => 'fa-solid fa-triangle-exclamation',
    'info' => 'fa-solid fa-circle-info',
    'light' => 'fa-solid fa-circle-question',
    'dark' => 'fa-solid fa-circle-question',
  ];

  return $map[hub_help_context($context)] ?? $map['info'];
}

function hub_help_icon_for_context(string $context): string {
  return hub_help_icon_class($context);
}

function hub_fontawesome_css(): string {
  return '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">';
}

function hub_help_modal_markup(): string {
  $editBase = hub_is_admin() ? '/admin/help-messages.php?id=' : '';

  return '
    <div class="modal help-modal" id="hub-help-modal" aria-hidden="true">
      <div class="modal-content help-modal-content" role="dialog" aria-modal="true" aria-labelledby="hub-help-title">
        <div class="modal-header">
          <div class="help-modal-heading">
            <span class="help-modal-icon" id="hub-help-icon"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></span>
            <h2 id="hub-help-title">Help</h2>
          </div>
          <button type="button" class="close-btn but2" data-help-close aria-label="Close help">&times;</button>
        </div>
        <div class="help-message" id="hub-help-message"></div>
        <div class="help-meta">
          <span id="hub-help-id"></span>
          ' . ($editBase !== '' ? '<a id="hub-help-edit" href="#">Edit help</a>' : '') . '
        </div>
      </div>
    </div>
    <script>
      (function () {
        var modal = document.getElementById("hub-help-modal");
        if (!modal) return;
        var title = document.getElementById("hub-help-title");
        var message = document.getElementById("hub-help-message");
        var idText = document.getElementById("hub-help-id");
        var edit = document.getElementById("hub-help-edit");
        var closeButtons = modal.querySelectorAll("[data-help-close]");
        function closeHelp() {
          modal.classList.remove("open");
          modal.classList.remove("help-modal-primary", "help-modal-secondary", "help-modal-success", "help-modal-danger", "help-modal-warning", "help-modal-info", "help-modal-light", "help-modal-dark");
          modal.setAttribute("aria-hidden", "true");
        }
        function openHelp(button) {
          var id = button.getAttribute("data-help-id") || "";
          var context = button.getAttribute("data-help-context") || "info";
          var iconClass = button.getAttribute("data-help-icon-class") || "fa-solid fa-circle-info";
          var icon = document.getElementById("hub-help-icon");
          title.textContent = button.getAttribute("data-help-heading") || "Help";
          if (icon) icon.innerHTML = "<i class=\"" + iconClass.replace(/"/g, "") + "\" aria-hidden=\"true\"></i>";
          message.innerHTML = button.getAttribute("data-help-message") || "";
          idText.textContent = id ? "Help ID: " + id : "";
          if (edit && id) {
            edit.href = "' . hub_h($editBase) . '" + encodeURIComponent(id);
            edit.style.display = "";
          }
          modal.classList.remove("help-modal-primary", "help-modal-secondary", "help-modal-success", "help-modal-danger", "help-modal-warning", "help-modal-info", "help-modal-light", "help-modal-dark");
          modal.classList.add("help-modal-" + context);
          modal.classList.add("open");
          modal.setAttribute("aria-hidden", "false");
        }
        document.addEventListener("click", function (event) {
          var button = event.target.closest(".help-trigger");
          if (button) {
            openHelp(button);
            return;
          }
          if (event.target === modal) closeHelp();
        });
        closeButtons.forEach(function (button) {
          button.addEventListener("click", closeHelp);
        });
        document.addEventListener("keydown", function (event) {
          if (event.key === "Escape") closeHelp();
        });
      })();
    </script>';
}
