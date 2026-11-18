<?php
/** Shared, session-independent limits. Files stay outside the document root. */
function hub_auth_client_ip(): string {
  $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  $trusted = array_filter(array_map('trim', explode(',', (string) getenv('HUB_AUTH_TRUSTED_PROXIES'))));
  if (!in_array($peer, $trusted, true)) {
    return $peer;
  }
  // Walk towards the client, trusting forwarded values only from configured peers.
  $chain = array_reverse(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
  foreach ($chain as $address) {
    $address = trim($address);
    if (!filter_var($address, FILTER_VALIDATE_IP)) {
      return $peer;
    }
    if (!in_array($address, $trusted, true)) {
      return $address;
    }
  }
  return $peer;
}

function hub_auth_consume_bucket(string $directory, string $key, int $limit, int $window): bool {
  // A bounded number of shards; expired identities are pruned while locked.
  $hash = hash('sha256', $key);
  $file = $directory . '/' . (hexdec(substr($hash, 0, 2)) % 64) . '.json';
  $handle = @fopen($file, 'c+b');
  if (!$handle) {
    return false;
  }
  try {
    if (!flock($handle, LOCK_EX)) {
      return false;
    }
    $raw = stream_get_contents($handle, 1048577);
    if ($raw === false || strlen($raw) > 1048576) {
      return false;
    }
    $entries = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($entries)) {
      return false;
    }
    $now = time();
    foreach ($entries as $identity => $entry) {
      if (!is_array($entry) || !isset($entry['until'], $entry['count'])) {
        return false;
      }
      if ($entry['until'] <= $now) {
        unset($entries[$identity]);
      }
    }
    if (!isset($entries[$hash]) && count($entries) >= 4096) {
      return false;
    }
    $entry = $entries[$hash] ?? ['until' => $now + $window, 'count' => 0];
    $allowed = $entry['count'] < $limit;
    $entry['count'] = min($limit + 1, $entry['count'] + 1);
    $entries[$hash] = $entry;
    $json = json_encode($entries, JSON_THROW_ON_ERROR);
    rewind($handle);
    if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
      return false;
    }
    return $allowed;
  } finally {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
}

function hub_auth_limits(): array {
  return [
    'continue' => [30, 120],
    'login' => [10, 100],
    'mail' => [3, 20],
  ];
}

function hub_auth_rate_limit_dir(): string {
  return PHP_SAPI === 'cli' && defined('HUB_AUTH_RATE_LIMIT_DIR')
    ? HUB_AUTH_RATE_LIMIT_DIR
    : dirname(__DIR__, 3) . '/private/auth-rate-limits';
}

/** Read only: opening/refreshing a form must not consume or extend its limits. */
function hub_auth_bucket_retry_after(string $directory, string $key, int $limit): int {
  $hash = hash('sha256', $key);
  $file = $directory . '/' . (hexdec(substr($hash, 0, 2)) % 64) . '.json';
  if (!file_exists($file)) {
    return 0;
  }
  $handle = @fopen($file, 'rb');
  if (!$handle) {
    return 900;
  }
  try {
    if (!flock($handle, LOCK_SH)) {
      return 900;
    }
    $raw = stream_get_contents($handle, 1048577);
    if ($raw === false || strlen($raw) > 1048576) {
      return 900;
    }
    $entries = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($entries)) {
      return 900;
    }
    $entry = $entries[$hash] ?? null;
    if ($entry === null) {
      return 0;
    }
    if (!is_array($entry) || !isset($entry['count'], $entry['until'])) {
      return 900;
    }
    return $entry['count'] >= $limit ? max(0, (int) $entry['until'] - time()) : 0;
  } finally {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
}

function hub_auth_login_retry_after(string $email): int {
  [$addressLimit, $ipLimit] = hub_auth_limits()['login'];
  $directory = hub_auth_rate_limit_dir();
  return max(
    hub_auth_bucket_retry_after($directory, 'login:email:' . strtolower(trim($email)), $addressLimit),
    hub_auth_bucket_retry_after($directory, 'login:ip:' . hub_auth_client_ip(), $ipLimit)
  );
}

function hub_auth_rate_limit(string $action, string $email): bool {
  $limits = hub_auth_limits();
  if (!isset($limits[$action])) {
    throw new InvalidArgumentException('Unknown authentication limit.');
  }
  $directory = hub_auth_rate_limit_dir();
  if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
    error_log('Hub authentication rate-limit storage unavailable.');
    return false;
  }
  [$addressLimit, $ipLimit] = $limits[$action];
  // Consume both counters even on denial; neither depends on account existence.
  $byAddress = hub_auth_consume_bucket($directory, $action . ':email:' . strtolower(trim($email)), $addressLimit, 900);
  $byIp = hub_auth_consume_bucket($directory, $action . ':ip:' . hub_auth_client_ip(), $ipLimit, 900);
  if (!$byAddress || !$byIp) {
    error_log('Hub authentication request limited or limit storage unavailable.');
  }
  return $byAddress && $byIp;
}

/** Never expose account lookup or mail delivery latency to anonymous callers. */
function hub_auth_defer_mail(string $kind, string $email): void {
  if (!in_array($kind, ['link', 'reset'], true)) {
    throw new InvalidArgumentException('Unknown authentication mail request.');
  }
  if (!hub_auth_rate_limit('mail', $email)) {
    return;
  }
  // Production uses PHP-FPM. Fail closed on other SAPIs rather than leak through
  // synchronous delivery. The public response remains the same in either case.
  if (!function_exists('fastcgi_finish_request')) {
    error_log('Hub authentication mail requires PHP-FPM fastcgi_finish_request.');
    return;
  }
  register_shutdown_function(static function () use ($kind, $email): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }
    fastcgi_finish_request();
    try {
      $result = $kind === 'link' ? hub_request_magic_link($email) : hub_request_password_reset($email);
      if (empty($result['sent'])) {
        error_log('Hub authentication mail was not sent (ineligible account or delivery failure).');
      }
    } catch (Throwable $e) {
      error_log('Hub authentication mail processing failed.');
    }
  });
}
