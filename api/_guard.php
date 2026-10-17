<?php
declare(strict_types=1);

/**
 * Shared helpers for API endpoints.
 *
 * - Simple rate limiting (no DB) using temp files
 * - Placeholder access gate for future auth
 */

function tutor_client_ip(): string {
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  if ($ip === '') $ip = '0.0.0.0';
  return $ip;
}

function tutor_now_ms(): int {
  return (int)floor(microtime(true) * 1000);
}

/**
 * Basic, file-based rate limit per IP.
 * - $bucket: a short name to separate endpoints
 */
function tutor_rate_limit_or_429(string $bucket, int $maxPerWindow = 20, int $windowSeconds = 60, int $minIntervalMs = 900): void {
  $ip = tutor_client_ip();
  $key = sha1($bucket . '|' . $ip);
  $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
  $path = $dir . DIRECTORY_SEPARATOR . 'anna_rl_' . $key . '.json';

  $t = time();

  $fp = @fopen($path, 'c+');
  if ($fp === false) return; // fail-open

  try {
    @flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: 'null', true);
    if (!is_array($data)) $data = [];
    if (!isset($data['times']) || !is_array($data['times'])) $data['times'] = [];

    // keep only recent timestamps (seconds)
    $times = [];
    foreach ($data['times'] as $ts) {
      $ts = (int)$ts;
      if ($ts > 0 && ($t - $ts) <= $windowSeconds) $times[] = $ts;
    }

    // min interval check (ms)
    $lastMs = isset($data['last_ms']) ? (int)$data['last_ms'] : 0;
    $deltaMs = tutor_now_ms() - $lastMs;
    if ($lastMs > 0 and $deltaMs < $minIntervalMs) {
      http_response_code(429);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['error' => ['code' => 'rate_limited', 'message' => 'Слишком часто. Подождите пару секунд и попробуйте снова.']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }

    if (count($times) >= $maxPerWindow) {
      http_response_code(429);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['error' => ['code' => 'rate_limited', 'message' => 'Слишком много запросов. Попробуйте позже.']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }

    $times[] = $t;
    $data['times'] = $times;
    $data['last_ms'] = tutor_now_ms();

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
  } finally {
    @flock($fp, LOCK_UN);
    fclose($fp);
  }
}

/**
 * Placeholder for future auth.
 * Keep this call in all paid endpoints so we can enforce access later.
 */
function tutor_access_gate(): void {
  // Intentionally empty for now (Stage 6.1).
  // Will be used in Stage 7.x authorization.
}
