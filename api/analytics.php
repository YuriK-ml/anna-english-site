<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

function ana_json(int $status, array $payload): void {
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
  }
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function ana_client_ip(): string {
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  return $ip !== '' ? $ip : '0.0.0.0';
}

function ana_now_ms(): int {
  return (int)floor(microtime(true) * 1000);
}

function ana_rate_limit_or_429(string $bucket, int $maxPerWindow = 120, int $windowSeconds = 60, int $minIntervalMs = 80): void {
  $ip = ana_client_ip();
  $key = sha1($bucket . '|' . $ip);
  $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
  $path = $dir . DIRECTORY_SEPARATOR . 'anna_ana_rl_' . $key . '.json';

  $t = time();
  $fp = @fopen($path, 'c+');
  if ($fp === false) return; // fail-open

  try {
    @flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: 'null', true);
    if (!is_array($data)) $data = [];
    if (!isset($data['times']) || !is_array($data['times'])) $data['times'] = [];

    $times = [];
    foreach ($data['times'] as $ts) {
      $ts = (int)$ts;
      if ($ts > 0 && ($t - $ts) <= $windowSeconds) $times[] = $ts;
    }

    $lastMs = isset($data['last_ms']) ? (int)$data['last_ms'] : 0;
    $deltaMs = ana_now_ms() - $lastMs;
    if ($lastMs > 0 && $deltaMs < $minIntervalMs) {
      ana_json(429, ['error' => ['code' => 'rate_limited', 'message' => 'Too frequent.']]);
    }

    if (count($times) >= $maxPerWindow) {
      ana_json(429, ['error' => ['code' => 'rate_limited', 'message' => 'Too many requests.']]);
    }

    $times[] = $t;
    $data['times'] = $times;
    $data['last_ms'] = ana_now_ms();

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
  } finally {
    @flock($fp, LOCK_UN);
    fclose($fp);
  }
}

function ana_private_dir(): string {
  $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
  if ($docRoot !== '') {
    return $docRoot . '/../private';
  }
  // Local fallback: project root ../private
  return __DIR__ . '/../private';
}

function ana_db_path(): string {
  $privateDir = ana_private_dir();
  return rtrim($privateDir, '/\\') . DIRECTORY_SEPARATOR . 'site_analytics.sqlite';
}

function ana_retention_days(): int {
  return 90;
}

function ana_pdo(): PDO {
  if (!class_exists('PDO')) {
    ana_json(500, ['error' => ['code' => 'server_config', 'message' => 'PDO is not available.']]);
  }

  $drivers = [];
  try { $drivers = PDO::getAvailableDrivers(); } catch (Throwable $e) {}
  if (!in_array('sqlite', $drivers, true)) {
    ana_json(500, ['error' => ['code' => 'server_config', 'message' => 'SQLite is not available (pdo_sqlite).']]);
  }

  $path = ana_db_path();
  $dir = dirname($path);
  if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
  }

  try {
    $pdo = new PDO('sqlite:' . $path, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA busy_timeout = 2000;');

    $pdo->exec(
      'CREATE TABLE IF NOT EXISTS page_events (' .
      'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
      'visitor_id TEXT NOT NULL,' .
      'visitor_key TEXT,' .
      'event_type TEXT NOT NULL,' .
      'page_id TEXT NOT NULL,' .
      'target TEXT,' .
      'created_at TEXT NOT NULL' .
      ');'
    );

    // Migration from older schema (visitor_key only)
    try {
      $cols = $pdo->query('PRAGMA table_info(page_events)')->fetchAll();
      $hasVisitorId = false;
      foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'visitor_id') { $hasVisitorId = true; break; }
      }
      if (!$hasVisitorId) {
        $pdo->exec('ALTER TABLE page_events ADD COLUMN visitor_id TEXT;');
      }
      // Backfill legacy rows so uniq counts can use visitor_id only.
      $pdo->exec('UPDATE page_events SET visitor_id = visitor_key WHERE visitor_id IS NULL AND visitor_key IS NOT NULL;');
    } catch (Throwable $e) {
      // ignore migration errors
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_events_created ON page_events(created_at);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_events_page ON page_events(page_id, created_at);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_events_target ON page_events(target, created_at);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_events_vid ON page_events(visitor_id, created_at);');

    return $pdo;
  } catch (Throwable $e) {
    error_log('Analytics DB open failed: ' . $e->getMessage());
    ana_json(500, ['error' => ['code' => 'server_config', 'message' => 'Analytics DB is not available.']]);
  }
}

function ana_is_valid_visitor_id(string $visitorId): bool {
  $visitorId = trim($visitorId);
  if ($visitorId === '') return false;
  if (strlen($visitorId) !== 36) return false;
  return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $visitorId);
}

function ana_normalize_page(string $page): ?string {
  $page = trim($page);
  if ($page === '') return null;
  if (strlen($page) > 160) return null;

  if ($page === '/articles/index.html') return '/articles/';
  if ($page === '/tutor/index.html') return '/tutor/';
  if ($page === '/articles') return '/articles/';
  if ($page === '/tutor') return '/tutor/';

  $allowed = [
    '/' => true,
    '/teacher.html' => true,
    '/ai.html' => true,
    '/articles/' => true,
    '/tutor/' => true,
  ];
  if (isset($allowed[$page])) return $page;

  if (preg_match('/^\/articles\/[a-z0-9-]+\.html$/i', $page)) {
    // Accept only existing article files to avoid arbitrary path spam.
    // Some hosts may have unexpected DOCUMENT_ROOT; use a safe fallback.
    $isOk = false;

    // 1) Primary: check under DOCUMENT_ROOT
    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docRoot !== '') {
      $candidate = $docRoot . $page;
      $realFile = realpath($candidate);
      $realArticles = realpath($docRoot . '/articles');
      if ($realFile && $realArticles && str_starts_with($realFile, $realArticles . DIRECTORY_SEPARATOR) && str_ends_with($realFile, '.html')) {
        $isOk = true;
      }
    }

    // 2) Fallback: resolve relative to repo structure (works even if DOCUMENT_ROOT is unexpected)
    if (!$isOk) {
      $articlesDir = realpath(__DIR__ . '/../articles');
      if ($articlesDir) {
        $fileName = basename($page);
        $realFile = realpath($articlesDir . DIRECTORY_SEPARATOR . $fileName);
        if ($realFile && str_starts_with($realFile, $articlesDir . DIRECTORY_SEPARATOR) && str_ends_with($realFile, '.html')) {
          $isOk = true;
        }
      }
    }

    return $isOk ? $page : null;
  }

  return null;
}
function ana_allowed_targets(): array {
  return [
    'tg_channel' => 'Telegram канал',
    'tg_contact' => 'Telegram Анны',
    'progressme_test' => 'Тест ProgressMe',
    'progressme_profile' => 'Профиль ProgressMe',
    'ai_diagnostics' => 'AI-диагностика / подбор программы',
    'ai_chef' => 'AI-повар (внешнее приложение)',
  ];
}

// ---- main ----

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  ana_json(405, ['error' => ['code' => 'method', 'message' => 'POST only']]);
}

ana_rate_limit_or_429('site_analytics', 240, 60, 50);

$len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($len > 4096) {
  ana_json(413, ['error' => ['code' => 'too_large', 'message' => 'Payload too large']]);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
  ana_json(400, ['error' => ['code' => 'bad_request', 'message' => 'Empty body']]);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  ana_json(400, ['error' => ['code' => 'bad_request', 'message' => 'Invalid JSON']]);
}

$event = isset($data['event']) ? (string)$data['event'] : '';
$page = isset($data['page']) ? (string)$data['page'] : '';
$visitorId = isset($data['visitor_id']) ? (string)$data['visitor_id'] : (isset($data['bid']) ? (string)$data['bid'] : '');
$target = isset($data['target']) ? (string)$data['target'] : '';

if ($event !== 'view' && $event !== 'out_click') {
  ana_json(400, ['error' => ['code' => 'bad_request', 'message' => 'Invalid event']]);
}

if (!ana_is_valid_visitor_id($visitorId)) {
  ana_json(400, ['error' => ['code' => 'bad_request', 'message' => 'Invalid visitor id']]);
}

$pageId = ana_normalize_page($page);
if ($pageId === null) {
  ana_json(400, ['error' => ['code' => 'bad_request', 'message' => 'Invalid page']]);
}

if ($event === 'out_click') {
  $targets = ana_allowed_targets();
  if (!isset($targets[$target])) {
    ana_json(400, ['error' => ['code' => 'bad_request', 'message' => 'Invalid target']]);
  }
} else {
  $target = '';
}

$pdo = ana_pdo();

// Retention cleanup (best-effort, occasional)
try {
  if (random_int(1, 100) === 1) {
    $days = ana_retention_days();
    $cut = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days');
    $st = $pdo->prepare('DELETE FROM page_events WHERE created_at < ?');
    $st->execute([$cut->format('c')]);
  }
} catch (Throwable $e) {
  // ignore
}

try {
  $createdAt = gmdate('c');
  $st = $pdo->prepare('INSERT INTO page_events(visitor_id, event_type, page_id, target, created_at) VALUES(?,?,?,?,?)');
  $st->execute([$visitorId, $event, $pageId, $target !== '' ? $target : null, $createdAt]);
} catch (Throwable $e) {
  error_log('Analytics insert failed: ' . $e->getMessage());
  // fail-open for users: return ok anyway
}

ana_json(200, ['ok' => true]);
