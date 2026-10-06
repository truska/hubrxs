<?php
require_once __DIR__ . '/bootstrap.php';

/** Existing preference names remain the source of truth for main contacts. */
function hub_company_fields(): array {
  return [
    'Company' => [
      'prefCompanyName' => ['Company name', 'text', 'RxSource'],
      'prefEmail' => ['Contact email', 'email', 'solutions@rxsource.com'],
      'prefWebsite' => ['Website', 'url', 'https://www.rxsource.com/'],
    ],
    'Canada' => [
      'prefCanadaLabel' => ['Office heading', 'text', 'CANADA'],
      'prefAddress1' => ['Address line 1', 'text', '74-556 Edward Ave'],
      'prefAddress2' => ['Address line 2', 'text', ''],
      'prefTown' => ['Town / city', 'text', 'Richmond Hill'],
      'prefCounty' => ['Province / state', 'text', ''],
      'prefCountry' => ['Country', 'text', 'Canada'],
      'prefPostcode' => ['Postal code', 'text', 'L4C 9Y5'],
      'prefTel1' => ['Telephone (include country code)', 'tel', '+1 905 883 4333'],
    ],
    'USA' => [
      'prefUSALabel' => ['Office heading', 'text', 'USA'],
      'prefUSAAddress' => ['Address (one line per address line)', 'textarea', "Unit 300\n1240 Forest Parkway\nWest Deptford\nNew Jersey\nUSA\n08066"],
      'prefTel2' => ['Telephone (include country code)', 'tel', '+1 905 883 4333'],
    ],
    'Europe' => [
      'prefEuropeLabel' => ['Office heading', 'text', 'EUROPE'],
      'prefEuropeAddress' => ['Address (one line per address line)', 'textarea', "Unit 506\nNorthwest Business Park, Ballycoolin\nDublin 15\nIreland"],
      'prefTel3' => ['Telephone (include country code)', 'tel', '+353 (1) 963-1100'],
    ],
    'Social links' => [
      'prefLinkedIn' => ['LinkedIn', 'url', 'https://www.linkedin.com/company/rxsource'],
      'prefTwitter' => ['X / Twitter', 'url', 'https://twitter.com/rxsource'],
      'prefInstagram' => ['Instagram', 'url', 'https://www.instagram.com/rxsource'],
      'prefYouTube' => ['YouTube', 'url', 'https://www.youtube.com/@RxSource'],
    ],
  ];
}

function hub_company_value(string $name): string {
  // Blank preferences deliberately hide optional details; no hard-coded fallback.
  return trim((string) hub_pref($name, ''));
}

function hub_company_url(string $name): string {
  $url = hub_company_value($name);
  return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : '';
}

function hub_company_offices(): array {
  $canada = [];
  foreach (['prefAddress1', 'prefAddress2', 'prefTown', 'prefCounty', 'prefCountry', 'prefPostcode'] as $name) {
    $value = hub_company_value($name);
    if ($value !== '') $canada[] = $value;
  }
  return [
    ['label' => hub_company_value('prefCanadaLabel'), 'address' => implode("\n", $canada), 'phone' => hub_company_value('prefTel1')],
    ['label' => hub_company_value('prefUSALabel'), 'address' => hub_company_value('prefUSAAddress'), 'phone' => hub_company_value('prefTel2')],
    ['label' => hub_company_value('prefEuropeLabel'), 'address' => hub_company_value('prefEuropeAddress'), 'phone' => hub_company_value('prefTel3')],
  ];
}

function hub_company_save(array $input): void {
  global $pdo, $DB_OK;
  $table = cms_preferences_table();
  if (!$DB_OK || !($pdo instanceof PDO) || !$table) throw new RuntimeException('Company preferences are unavailable.');
  $values = [];
  foreach (hub_company_fields() as $fields) foreach ($fields as $name => [$label, $type]) {
    if (!isset($input[$name]) || !is_string($input[$name])) throw new RuntimeException('Missing field: ' . $label);
    $value = trim(str_replace("\r\n", "\n", $input[$name]));
    if (strlen($value) > 2048) throw new RuntimeException($label . ' is too long.');
    if ($value !== '' && $type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid contact email.');
    if ($value !== '' && $type === 'url' && (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true))) throw new RuntimeException($label . ' must be an http or https URL.');
    $values[$name] = $value;
  }
  $pdo->beginTransaction();
  try {
    $find = $pdo->prepare('SELECT id FROM `' . $table . '` WHERE name = :name AND archived = 0');
    $update = $pdo->prepare('UPDATE `' . $table . '` SET value = :value, modified = NOW() WHERE id = :id');
    foreach ($values as $name => $value) {
      $find->execute([':name' => $name]);
      $id = $find->fetchColumn();
      if (!$id) throw new RuntimeException('Company preference missing. Run the company details installer.');
      $update->execute([':value' => $value, ':id' => $id]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}
