<?php
declare(strict_types=1);

/**
 * Shared helpers for Tutor API endpoints (chat/stt/tts) and auth/admin pages.
 *
 * Goals:
 * - Server-side access gate (student session required for paid endpoints)
 * - Simple rate limiting (no DB) using temp files
 * - SQLite storage for students (outside public_html)
 */

function tutor_is_https(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
  return false;
}

function tutor_json_respond(int $status, array $payload): void {
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
  }
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function tutor_client_ip(): string {
  // Prefer REMOTE_ADDR. Do not blindly trust X-Forwarded-For from the public internet.
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  if ($ip === '') $ip = '0.0.0.0';
  return $ip;
}

function tutor_now_ms(): int {
  return (int)floor(microtime(true) * 1000);
}

function tutor_session_start(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;

  @ini_set('session.use_only_cookies', '1');
  @ini_set('session.use_strict_mode', '1');
  @ini_set('session.cookie_httponly', '1');
  @ini_set('session.cookie_secure', tutor_is_https() ? '1' : '0');
  @ini_set('session.cookie_samesite', 'Lax');

  session_name('anna_tutor_sess');
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'secure' => tutor_is_https(),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }

  session_start();
}

/**
 * Basic, file-based rate limit per IP.
 * - $bucket: short name to separate endpoints
 */
function tutor_rate_limit_or_429(string $bucket, int $maxPerWindow = 20, int $windowSeconds = 60, int $minIntervalMs = 900, bool $respondJson = true): void {
  $ip = tutor_client_ip();
  $key = sha1($bucket . '|' . $ip);
  $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
  $path = $dir . DIRECTORY_SEPARATOR . 'anna_rl_' . $key . '.json';

  $t = time();

  $respond429 = function(string $msg) use ($respondJson): void {
    if ($respondJson) {
      tutor_json_respond(429, ['error' => ['code' => 'rate_limited', 'message' => $msg]]);
    }
    if (!headers_sent()) {
      header('Content-Type: text/plain; charset=UTF-8');
      header('Cache-Control: no-store');
      header('X-Content-Type-Options: nosniff');
    }
    http_response_code(429);
    echo $msg;
    exit;
  };

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
    if ($lastMs > 0 && $deltaMs < $minIntervalMs) {
      $respond429('Слишком часто. Подождите пару секунд и попробуйте снова.');
    }

    if (count($times) >= $maxPerWindow) {
      $respond429('Слишком много запросов. Попробуйте позже.');
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

function tutor_load_auth_config(): void {
  // Avoid double-load.
  if (defined('TUTOR_DB_PATH') || defined('TUTOR_CODE_PEPPER') || defined('TUTOR_ADMIN_PASSWORD_HASH')) {
    return;
  }

  // 1) Recommended: env var path
  $envPath = (string)getenv('TUTOR_PRIVATE_CONFIG');
  if ($envPath !== '' && is_file($envPath)) {
    require $envPath;
    return;
  }

  // 2) Local dev fallback: project root tutor_auth_config.php (gitignored)
  $local = realpath(__DIR__ . '/../tutor_auth_config.php');
  if ($local && is_file($local)) {
    require $local;
    return;
  }

  // 3) Hosting layout: ../private/tutor_auth_config.php relative to DOCUMENT_ROOT
  $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
  if ($docRoot !== '') {
    $candidate = realpath($docRoot . '/../private/tutor_auth_config.php');
    if ($candidate && is_file($candidate)) {
      require $candidate;
      return;
    }
  }
}

function tutor_require_const(string $name): string {
  if (!defined($name)) {
    tutor_json_respond(500, ['error' => ['code' => 'server_config', 'message' => 'Сервер не настроен (missing auth config).']]);
  }
  $val = constant($name);
  if (!is_string($val) || trim($val) === '') {
    tutor_json_respond(500, ['error' => ['code' => 'server_config', 'message' => 'Сервер не настроен (invalid auth config).']]);
  }
  return $val;
}

function tutor_pdo(): PDO {
  tutor_load_auth_config();

  // If config did not define explicit DB path, use production default.
  $dbPath = defined('TUTOR_DB_PATH') && is_string(constant('TUTOR_DB_PATH')) ? (string)constant('TUTOR_DB_PATH') : '';
  if ($dbPath === '') {
    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docRoot !== '') {
      $dbPath = $docRoot . '/../private/tutor.sqlite';
    } else {
      // Local fallback (must be explicitly configured for security).
      tutor_json_respond(500, ['error' => ['code' => 'server_config', 'message' => 'Сервер не настроен (db path).']]);
    }
  }

  if (!class_exists('PDO')) {
    tutor_json_respond(500, ['error' => ['code' => 'server_config', 'message' => 'PDO недоступен на сервере.']]);
  }

  $drivers = [];
  try {
    $drivers = PDO::getAvailableDrivers();
  } catch (Throwable $e) {
  }

  if (!in_array('sqlite', $drivers, true)) {
    error_log('Tutor auth: PDO sqlite driver is not available');
    tutor_json_respond(500, ['error' => ['code' => 'server_config', 'message' => 'SQLite недоступен на сервере (pdo_sqlite).']]);
  }

  $dir = dirname($dbPath);
  if (!is_dir($dir)) {
    // In production, the private dir should already exist.
    @mkdir($dir, 0700, true);
  }

  try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 2000;');

    tutor_db_migrate($pdo);
    return $pdo;
  } catch (Throwable $e) {
    error_log('Tutor auth: DB open failed: ' . $e->getMessage());
    tutor_json_respond(500, ['error' => ['code' => 'server_config', 'message' => 'База данных недоступна.']]);
  }
}

function tutor_db_migrate(PDO $pdo): void {
  $pdo->exec(
    'CREATE TABLE IF NOT EXISTS students (' .
    'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
    'name TEXT NOT NULL,' .
    'active INTEGER NOT NULL DEFAULT 1,' .
    'archived INTEGER NOT NULL DEFAULT 0,' .
    'code_lookup TEXT,' .
    'created_at TEXT NOT NULL,' .
    'updated_at TEXT NOT NULL' .
    ');'
  );
  $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_students_code_lookup ON students(code_lookup);');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_students_active ON students(active, archived);');

  // Placeholder for future cost/limits accounting.
  $pdo->exec(
    'CREATE TABLE IF NOT EXISTS usage_events (' .
    'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
    'student_id INTEGER,' .
    'endpoint TEXT NOT NULL,' .
    'created_at TEXT NOT NULL,' .
    'meta_json TEXT,' .
    'FOREIGN KEY(student_id) REFERENCES students(id)' .
    ');'
  );
}

function tutor_code_lookup(string $code): string {
  tutor_load_auth_config();
  $pepper = tutor_require_const('TUTOR_CODE_PEPPER');
  return hash_hmac('sha256', $code, $pepper);
}

function tutor_clear_student_session(): void {
  tutor_session_start();
  unset($_SESSION['tutor_student_id'], $_SESSION['tutor_student_name']);
}

/**
 * Access gate for paid endpoints.
 * Requires a valid student session.
 */
function tutor_access_gate(): void {
  tutor_session_start();

  $studentId = isset($_SESSION['tutor_student_id']) ? (int)$_SESSION['tutor_student_id'] : 0;
  if ($studentId <= 0) {
    tutor_json_respond(401, ['error' => ['code' => 'auth_required', 'message' => 'Введите код доступа, чтобы пользоваться AI Tutor.']]);
  }

  // Verify that the student is still active.
  $pdo = tutor_pdo();
  try {
    $st = $pdo->prepare('SELECT id, name, active, archived FROM students WHERE id = ? LIMIT 1');
    $st->execute([$studentId]);
    $row = $st->fetch();
  } catch (Throwable $e) {
    error_log('Tutor auth: student lookup failed: ' . $e->getMessage());
    tutor_json_respond(500, ['error' => ['code' => 'server_config', 'message' => 'Сервер не настроен.']]);
  }

  if (!$row || (int)$row['archived'] === 1 || (int)$row['active'] !== 1) {
    tutor_clear_student_session();
    tutor_json_respond(401, ['error' => ['code' => 'auth_required', 'message' => 'Доступ отключён. Введите код доступа.']]);
  }

  // Keep a copy of the name for UI/debug (not security-critical).
  $_SESSION['tutor_student_name'] = (string)$row['name'];
}


/**
 * Best-effort activity logging for admin statistics.
 *
 * IMPORTANT:
 * - Logs only non-sensitive metadata.
 * - Never blocks Tutor if it fails.
 */
function tutor_usage_log_success(PDO $pdo, int $studentId, string $endpoint, array $meta = []): void {
  if ($studentId <= 0) return;

  static $allowed = [
    'login' => true,
    'chat' => true,
    'stt' => true,
    'tts' => true,
  ];
  if (!isset($allowed[$endpoint])) return;

  $createdAt = gmdate('c');

  $metaJson = null;
  if (!empty($meta)) {
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($metaJson)) $metaJson = null;
    if (is_string($metaJson) && strlen($metaJson) > 2000) {
      $metaJson = substr($metaJson, 0, 2000);
    }
  }

  try {
    $st = $pdo->prepare('INSERT INTO usage_events(student_id, endpoint, created_at, meta_json) VALUES(?,?,?,?)');
    $st->execute([$studentId, $endpoint, $createdAt, $metaJson]);
  } catch (Throwable $e) {
    // Fail-open: do not break paid endpoints because of stats.
    error_log('Tutor usage_events insert failed: ' . $e->getMessage());
  }
}
