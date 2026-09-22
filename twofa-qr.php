<?php
require_once __DIR__ . '/includes/app/auth.php';

if (hub_is_logged_in()) {
  $user = hub_current_user();
  $record = $user ? hub_totp_record_for_user((int) ($user['id'] ?? 0)) : null;
  if (!$user || empty($_SESSION['hub_account_totp_setup']) || !$record || (int) ($record['enabled'] ?? 0) === 1) {
    http_response_code(404);
    exit;
  }
  $setupData = [
    'otpauth_uri' => hub_totp_otpauth_uri($user, (string) ($record['secret'] ?? '')),
  ];
} else {
  $user = hub_pending_twofa_user();
  $setupData = hub_pending_twofa_setup_data($user);
  if (!$user || !$setupData || hub_pending_twofa_method() !== 'totp') {
    http_response_code(404);
    exit;
  }
}

$qrencode = is_executable('/usr/bin/qrencode') ? '/usr/bin/qrencode' : '';
if ($qrencode === '' && function_exists('shell_exec')) {
  $qrencode = trim((string) shell_exec('command -v qrencode'));
}
if ($qrencode === '' || !function_exists('exec')) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'QR generator unavailable.';
  exit;
}

$inputFile = tempnam(sys_get_temp_dir(), 'hub_totp_uri_');
$outputFile = tempnam(sys_get_temp_dir(), 'hub_totp_qr_');
if ($inputFile === false || $outputFile === false) {
  http_response_code(500);
  exit;
}

try {
  file_put_contents($inputFile, (string) $setupData['otpauth_uri']);
  chmod($inputFile, 0600);
  $cmd = escapeshellcmd($qrencode) . ' -o ' . escapeshellarg($outputFile) . ' -t PNG -s 7 -m 2 -r ' . escapeshellarg($inputFile);
  exec($cmd, $unused, $exitCode);
  if ($exitCode !== 0 || !is_file($outputFile) || filesize($outputFile) <= 0) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate QR code.';
    exit;
  }

  header('Content-Type: image/png');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  readfile($outputFile);
} finally {
  if (is_string($inputFile) && is_file($inputFile)) {
    unlink($inputFile);
  }
  if (is_string($outputFile) && is_file($outputFile)) {
    unlink($outputFile);
  }
}
