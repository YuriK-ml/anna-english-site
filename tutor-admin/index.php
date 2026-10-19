<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../api/_guard.php';

tutor_session_start();
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function csrf_token(): string {
  if (!isset($_SESSION['tutor_csrf']) || !is_string($_SESSION['tutor_csrf']) || strlen($_SESSION['tutor_csrf']) < 16) {
    $_SESSION['tutor_csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['tutor_csrf'];
}

function csrf_check_or_403(): void {
  $t = (string)($_POST['csrf'] ?? '');
  $s = (string)($_SESSION['tutor_csrf'] ?? '');
  if ($t === '' || $s === '' || !hash_equals($s, $t)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
}

function is_admin(): bool {
  return isset($_SESSION['tutor_admin']) && $_SESSION['tutor_admin'] === 1;
}

function require_admin(): void {
  if (!is_admin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
}

function generate_code(int $len = 18): string {
  // URL-safe base64-ish (letters/digits/_-)
  $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  return substr($raw, 0, $len);
}

$flash = '';
$flashType = 'info';
$oneTimeCode = '';
$deletePendingId = 0;
$deletePendingToken = '';
$deletePendingName = '';

// One-time flash message (PRG)
if (isset($_SESSION['tutor_admin_flash']) && is_string($_SESSION['tutor_admin_flash'])) {
  $flash = (string)$_SESSION['tutor_admin_flash'];
}
unset($_SESSION['tutor_admin_flash']);

if (isset($_SESSION['tutor_admin_flash_type']) && is_string($_SESSION['tutor_admin_flash_type'])) {
  $flashType = (string)$_SESSION['tutor_admin_flash_type'];
}
unset($_SESSION['tutor_admin_flash_type']);

if (isset($_SESSION['tutor_admin_one_time_code']) && is_string($_SESSION['tutor_admin_one_time_code'])) {
  $oneTimeCode = (string)$_SESSION['tutor_admin_one_time_code'];
}
unset($_SESSION['tutor_admin_one_time_code']);

// Pending delete confirmation (2-step).
if (isset($_SESSION['tutor_admin_delete_pending']) && is_array($_SESSION['tutor_admin_delete_pending'])) {
  $p = $_SESSION['tutor_admin_delete_pending'];
  $deletePendingId = isset($p['id']) ? (int)$p['id'] : 0;
  $deletePendingToken = isset($p['token']) && is_string($p['token']) ? (string)$p['token'] : '';
  $deletePendingName = isset($p['name']) && is_string($p['name']) ? (string)$p['name'] : '';
}

// Load admin password hash from private config
// Expected in private/tutor_auth_config.php:
// define('TUTOR_ADMIN_PASSWORD_HASH', '...');

tutor_load_auth_config();
$adminHash = defined('TUTOR_ADMIN_PASSWORD_HASH') ? (string)constant('TUTOR_ADMIN_PASSWORD_HASH') : '';

$pdo = null;
try {
  $pdo = tutor_pdo();
} catch (Throwable $e) {
  // tutor_pdo already responded with JSON in some cases; for admin we show a safe message.
  http_response_code(500);
  echo 'Server is not configured.';
  exit;
}

// Migration: Archived status is deprecated. Treat archived students as Disabled.
try {
  $now = gmdate('c');
  $st = $pdo->prepare('UPDATE students SET active=0, archived=0, updated_at=? WHERE archived=1');
  $st->execute([$now]);
} catch (Throwable $e) {
  // Best-effort only.
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  // Rate limit only POST actions.
  // Keep admin_login protected from brute force, but do not block normal admin usage.
  if ($action === 'admin_login') {
    tutor_rate_limit_or_429('admin_login', 12, 60, 900, false);
  } else {
    tutor_rate_limit_or_429('admin_actions', 240, 60, 0, false);
  }

  if ($action === 'admin_login') {
    csrf_check_or_403();
    $pass = (string)($_POST['password'] ?? '');
    if ($adminHash === '' || !is_string($adminHash)) {
      $flash = 'Admin config is missing.';
      $flashType = 'error';
    } elseif (!password_verify($pass, $adminHash)) {
      $flash = 'Неверный пароль.';
      $flashType = 'error';
    } else {
      try { session_regenerate_id(true); } catch (Throwable $e) {}
      $_SESSION['tutor_admin'] = 1;
      $flash = 'Вы вошли как администратор.';
      $flashType = 'ok';
    }
  } elseif ($action === 'admin_logout') {
    csrf_check_or_403();
    unset($_SESSION['tutor_admin']);
    try { session_regenerate_id(true); } catch (Throwable $e) {}
    $flash = 'Вы вышли.';
    $flashType = 'ok';
  } elseif ($action === 'add_student') {
    csrf_check_or_403();
    require_admin();
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || strlen($name) > 80) {
      $flash = 'Введите имя ученика (до 80 символов).';
      $flashType = 'error';
    } else {
      $code = generate_code();
      $lookup = tutor_code_lookup($code);
      $now = gmdate('c');
      try {
        $st = $pdo->prepare('INSERT INTO students(name, active, archived, code_lookup, created_at, updated_at) VALUES(?,1,0,?,?,?)');
        $st->execute([$name, $lookup, $now, $now]);
        $oneTimeCode = $code;
        $flash = 'Ученик добавлен. Код показан один раз ниже.';
        $flashType = 'ok';
      } catch (Throwable $e) {
        error_log('Admin add_student failed: ' . $e->getMessage());
        $flash = 'Не удалось добавить ученика.';
        $flashType = 'error';
      }
    }
  } elseif ($action === 'regen_code') {
    csrf_check_or_403();
    require_admin();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $flash = 'Некорректный ученик.';
      $flashType = 'error';
    } else {
      $code = generate_code();
      $lookup = tutor_code_lookup($code);
      $now = gmdate('c');
      try {
        $st = $pdo->prepare('UPDATE students SET code_lookup=?, updated_at=? WHERE id=?');
        $st->execute([$lookup, $now, $id]);
        $oneTimeCode = $code;
        $flash = 'Код перевыпущен. Новый код показан один раз ниже.';
        $flashType = 'ok';
      } catch (Throwable $e) {
        error_log('Admin regen_code failed: ' . $e->getMessage());
        $flash = 'Не удалось перевыпустить код.';
        $flashType = 'error';
      }
    }
  } elseif ($action === 'toggle_active') {
    csrf_check_or_403();
    require_admin();
    $id = (int)($_POST['id'] ?? 0);
    $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;
    $now = gmdate('c');
    try {
      $st = $pdo->prepare('UPDATE students SET active=?, updated_at=? WHERE id=?');
      $st->execute([$active, $now, $id]);
      $flash = $active ? 'Доступ включён.' : 'Доступ отключён.';
      $flashType = 'ok';
    } catch (Throwable $e) {
      error_log('Admin toggle_active failed: ' . $e->getMessage());
      $flash = 'Не удалось изменить доступ.';
      $flashType = 'error';
    }
  } elseif ($action === 'delete_student') {
    csrf_check_or_403();
    require_admin();
    // Deprecated action name: keep for backward compatibility if any old forms exist.
    $flash = 'Для удаления используйте двухэтапное подтверждение.';
    $flashType = 'error';
  } elseif ($action === 'delete_student_prepare') {
    csrf_check_or_403();
    require_admin();

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $flash = 'Некорректный ученик.';
      $flashType = 'error';
    } else {
      try {
        $st = $pdo->prepare('SELECT id, name FROM students WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!is_array($row)) {
          $flash = 'Ученик не найден.';
          $flashType = 'error';
        } else {
          $_SESSION['tutor_admin_delete_pending'] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'token' => bin2hex(random_bytes(16)),
            'at' => time(),
          ];
          $flash = '';
          $flashType = 'info';
        }
      } catch (Throwable $e) {
        error_log('Admin delete_student_prepare failed: ' . $e->getMessage());
        $flash = 'Не удалось подготовить удаление.';
        $flashType = 'error';
      }
    }
  } elseif ($action === 'delete_student_cancel') {
    csrf_check_or_403();
    require_admin();
    unset($_SESSION['tutor_admin_delete_pending']);
    $flash = 'Удаление отменено.';
    $flashType = 'ok';
  } elseif ($action === 'delete_student_confirm') {
    csrf_check_or_403();
    require_admin();

    $id = (int)($_POST['id'] ?? 0);
    $token = (string)($_POST['token'] ?? '');

    $pending = (isset($_SESSION['tutor_admin_delete_pending']) && is_array($_SESSION['tutor_admin_delete_pending']))
      ? $_SESSION['tutor_admin_delete_pending']
      : null;
    $pendingId = is_array($pending) && isset($pending['id']) ? (int)$pending['id'] : 0;
    $pendingToken = is_array($pending) && isset($pending['token']) && is_string($pending['token']) ? (string)$pending['token'] : '';

    if ($id <= 0 || $token === '' || $pendingId <= 0 || $pendingToken === '') {
      $flash = 'Подтверждение удаления не найдено.';
      $flashType = 'error';
    } elseif ($id !== $pendingId || !hash_equals($pendingToken, $token)) {
      $flash = 'Подтверждение удаления недействительно.';
      $flashType = 'error';
    } else {
      try {
        $pdo->beginTransaction();

        $st1 = $pdo->prepare('DELETE FROM usage_events WHERE student_id = ?');
        $st1->execute([$id]);

        $st2 = $pdo->prepare('DELETE FROM students WHERE id = ?');
        $st2->execute([$id]);
        $deleted = (int)$st2->rowCount();

        $pdo->commit();
        unset($_SESSION['tutor_admin_delete_pending']);

        if ($deleted > 0) {
          $flash = 'Ученик удалён безвозвратно.';
          $flashType = 'ok';
        } else {
          $flash = 'Ученик не найден.';
          $flashType = 'error';
        }
      } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) {}
        error_log('Admin delete_student_confirm failed: ' . $e->getMessage());
        $flash = 'Не удалось удалить ученика.';
        $flashType = 'error';
      }
    }
  }

  // POST -> Redirect -> GET (PRG) to prevent duplicate actions on refresh.
  $_SESSION['tutor_admin_flash'] = $flash;
  $_SESSION['tutor_admin_flash_type'] = $flashType;
  if ($oneTimeCode !== '') {
    $_SESSION['tutor_admin_one_time_code'] = $oneTimeCode;
  }

  $target = (string)($_SERVER['REQUEST_URI'] ?? '/tutor-admin/');
  $target = strtok($target, '?');
  if (!is_string($target) || $target === '') $target = '/tutor-admin/';
  header('Location: ' . $target, true, 303);
  exit;

}
// Load list + activity stats
$students = [];
$summaryToday = ['login' => 0, 'chat' => 0, 'stt' => 0, 'tts' => 0];
$summaryMonth = ['login' => 0, 'chat' => 0, 'stt' => 0, 'tts' => 0];
$demoAll = ['demo_start' => 0, 'demo_chat' => 0, 'demo_stt' => 0, 'demo_tts' => 0, 'last_utc' => ''];
$demoToday = ['demo_start' => 0, 'demo_chat' => 0, 'demo_stt' => 0, 'demo_tts' => 0];
$demoMonth = ['demo_start' => 0, 'demo_chat' => 0, 'demo_stt' => 0, 'demo_tts' => 0];

function msk_dt(string $utcIso): string {
  $utcIso = trim($utcIso);
  if ($utcIso === '') return '';
  try {
    $dt = new DateTimeImmutable($utcIso);
    $dt = $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
    return $dt->format('Y-m-d H:i');
  } catch (Throwable $e) {
    return $utcIso;
  }
}

function load_usage_summary(PDO $pdo, string $startUtc, string $endUtc): array {
  $out = ['login' => 0, 'chat' => 0, 'stt' => 0, 'tts' => 0];
  try {
    $st = $pdo->prepare('SELECT endpoint, COUNT(*) AS c FROM usage_events WHERE created_at >= ? AND created_at < ? GROUP BY endpoint');
    $st->execute([$startUtc, $endUtc]);
    $rows = $st->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $r) {
        $ep = is_array($r) ? (string)($r['endpoint'] ?? '') : '';
        if ($ep !== '' && array_key_exists($ep, $out)) {
          $out[$ep] = (int)($r['c'] ?? 0);
        }
      }
    }
  } catch (Throwable $e) {
    error_log('Admin usage summary failed: ' . $e->getMessage());
  }
  return $out;
}

function load_demo_summary(PDO $pdo, string $startUtc, string $endUtc): array {
  $out = ['demo_start' => 0, 'demo_chat' => 0, 'demo_stt' => 0, 'demo_tts' => 0];
  try {
    $st = $pdo->prepare(
      "SELECT endpoint, COUNT(*) AS c FROM usage_events " .
      "WHERE student_id IS NULL AND created_at >= ? AND created_at < ? " .
      "AND endpoint IN ('demo_start','demo_chat','demo_stt','demo_tts') GROUP BY endpoint"
    );
    $st->execute([$startUtc, $endUtc]);
    $rows = $st->fetchAll();
    if (is_array($rows)) {
      foreach ($rows as $r) {
        $ep = is_array($r) ? (string)($r['endpoint'] ?? '') : '';
        if ($ep !== '' && array_key_exists($ep, $out)) {
          $out[$ep] = (int)($r['c'] ?? 0);
        }
      }
    }
  } catch (Throwable $e) {
    error_log('Admin demo summary failed: ' . $e->getMessage());
  }
  return $out;
}

try {
  // Students + per-student totals
  $sql =
    'SELECT s.id, s.name, s.active, s.created_at, s.updated_at,' .
    ' COALESCE(u.login_count,0) AS login_count,' .
    ' COALESCE(u.chat_count,0) AS chat_count,' .
    ' COALESCE(u.stt_count,0) AS stt_count,' .
    ' COALESCE(u.tts_count,0) AS tts_count,' .
    ' u.last_utc AS last_utc' .
    ' FROM students s' .
    ' LEFT JOIN (' .
    "   SELECT student_id,\n" .
    "     SUM(CASE WHEN endpoint='login' THEN 1 ELSE 0 END) AS login_count,\n" .
    "     SUM(CASE WHEN endpoint='chat' THEN 1 ELSE 0 END) AS chat_count,\n" .
    "     SUM(CASE WHEN endpoint='stt' THEN 1 ELSE 0 END) AS stt_count,\n" .
    "     SUM(CASE WHEN endpoint='tts' THEN 1 ELSE 0 END) AS tts_count,\n" .
    "     MAX(created_at) AS last_utc\n" .
    "   FROM usage_events\n" .
    "   GROUP BY student_id\n" .
    " ) u ON u.student_id = s.id" .
    ' ORDER BY s.id DESC';

  $st = $pdo->query($sql);
  $students = $st ? $st->fetchAll() : [];
} catch (Throwable $e) {
  error_log('Admin list failed: ' . $e->getMessage());
}

// Summary: store UTC in DB, but count by Moscow day/month boundaries.

// Demo totals (student_id IS NULL)
try {
  $st = $pdo->query(
    "SELECT
      SUM(CASE WHEN endpoint='demo_start' THEN 1 ELSE 0 END) AS demo_start,
      SUM(CASE WHEN endpoint='demo_chat' THEN 1 ELSE 0 END) AS demo_chat,
      SUM(CASE WHEN endpoint='demo_stt' THEN 1 ELSE 0 END) AS demo_stt,
      SUM(CASE WHEN endpoint='demo_tts' THEN 1 ELSE 0 END) AS demo_tts,
      MAX(created_at) AS last_utc
    FROM usage_events
    WHERE student_id IS NULL AND endpoint IN ('demo_start','demo_chat','demo_stt','demo_tts')"
  );
  $row = $st ? $st->fetch() : null;
  if (is_array($row)) {
    $demoAll['demo_start'] = (int)($row['demo_start'] ?? 0);
    $demoAll['demo_chat'] = (int)($row['demo_chat'] ?? 0);
    $demoAll['demo_stt'] = (int)($row['demo_stt'] ?? 0);
    $demoAll['demo_tts'] = (int)($row['demo_tts'] ?? 0);
    $demoAll['last_utc'] = (string)($row['last_utc'] ?? '');
  }
} catch (Throwable $e) {
  error_log('Admin demo totals failed: ' . $e->getMessage());
}

// Summary: store UTC in DB, but count by Moscow day/month boundaries.
try {
  $tzMsk = new DateTimeZone('Europe/Moscow');
  $tzUtc = new DateTimeZone('UTC');

  $todayStartUtc = (new DateTimeImmutable('today', $tzMsk))->setTimezone($tzUtc)->format('c');
  $todayEndUtc = (new DateTimeImmutable('tomorrow', $tzMsk))->setTimezone($tzUtc)->format('c');

  $monthStartMsk = new DateTimeImmutable('first day of this month 00:00:00', $tzMsk);
  $monthEndMsk = $monthStartMsk->modify('+1 month');
  $monthStartUtc = $monthStartMsk->setTimezone($tzUtc)->format('c');
  $monthEndUtc = $monthEndMsk->setTimezone($tzUtc)->format('c');

  $summaryToday = load_usage_summary($pdo, $todayStartUtc, $todayEndUtc);
  $summaryMonth = load_usage_summary($pdo, $monthStartUtc, $monthEndUtc);
  $demoToday = load_demo_summary($pdo, $todayStartUtc, $todayEndUtc);
  $demoMonth = load_demo_summary($pdo, $monthStartUtc, $monthEndUtc);
} catch (Throwable $e) {
  error_log('Admin summary window failed: ' . $e->getMessage());
}

?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tutor Admin — Anna English AI</title>
  <style>
    :root{
      --bg:#070a0f;
      --card: rgba(0,0,0,0.55);
      --card2: rgba(0,0,0,0.35);
      --border: rgba(255,255,255,0.08);
      --text: rgba(255,255,255,0.95);
      --muted: rgba(255,255,255,0.75);
      --ok:#2ecc71;
      --err:#e74c3c;
      --accent:#3498db;
      --shadow: 0 14px 40px rgba(0,0,0,0.45);
    }
    body{ margin:0; font-family: system-ui, -apple-system, Segoe UI, Arial, sans-serif; background: var(--bg); color: var(--text); }
    .wrap{ max-width: 980px; margin: 0 auto; padding: 24px 16px 60px; }
    .top{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom: 14px; }
    a{ color: rgba(255,255,255,0.85); text-decoration:none; }
    a:hover{ color:#fff; }
    .card{ background: var(--card); border:1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow); padding: 16px; margin: 14px 0; }
    h1{ margin:0; font-size: 22px; }
    h2{ margin:0 0 10px; font-size: 16px; }
    .muted{ color: var(--muted); }
    .flash{ padding:10px 12px; border-radius: 10px; border:1px solid var(--border); background: var(--card2); }
    .flash.ok{ border-color: rgba(46,204,113,0.35); }
    .flash.error{ border-color: rgba(231,76,60,0.35); }
    .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    input{ background: rgba(0,0,0,0.35); color: var(--text); border:1px solid var(--border); border-radius: 10px; padding:10px 10px; outline:none; }
    button{ border:0; border-radius: 10px; padding:10px 12px; background: var(--accent); color:#fff; cursor:pointer; }
    button.ghost{ background: transparent; border:1px solid var(--border); color: rgba(255,255,255,0.9); }
    button.danger{ background: var(--err); }
    table{ width:100%; border-collapse: collapse; font-size: 14px; }
    th, td{ padding:10px 8px; border-bottom:1px solid rgba(255,255,255,0.06); vertical-align: top; }
    th{ text-align:left; color: rgba(255,255,255,0.8); font-weight:600; }
    .badge{ display:inline-block; padding:2px 8px; border-radius: 999px; border:1px solid var(--border); font-size: 12px; color: rgba(255,255,255,0.85); }
    .badge.off{ opacity:0.65; }
    .codebox{ margin-top:10px; padding:12px; border-radius: 12px; border:1px dashed rgba(255,255,255,0.18); background: rgba(0,0,0,0.25); font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
    .stats-wrap{ display:flex; gap:12px; flex-wrap:wrap; }
    .stats-block{ flex:1; min-width: 260px; background: var(--card2); border:1px solid var(--border); border-radius: 12px; padding: 12px; }
    .stats-title{ font-weight: 700; margin: 0 0 8px; }
    .kpi-grid{ display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:10px; }
    .kpi{ border:1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.18); border-radius: 12px; padding: 10px; }
    .kpi .v{ font-size: 18px; font-weight: 800; line-height: 1.1; }
    .kpi .l{ font-size: 12px; color: var(--muted); margin-top: 4px; }
    @media (max-width: 720px){
      th:nth-child(1), td:nth-child(1) { display:none; }
      .kpi-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div class="row" style="justify-content:flex-start;">
        <h1 style="margin:0;">Tutor Admin</h1>
        <?php if (is_admin()): ?>
          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
            <input type="hidden" name="action" value="admin_logout" />
            <button class="ghost" type="submit">Выйти</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="row" style="justify-content:flex-end;">
        <a href="/tutor-admin/analytics.php">📊 Аналитика сайта →</a>
      </div>
    </div>

    <?php if ($flash !== ''): ?>
      <div class="flash <?php echo $flashType === 'ok' ? 'ok' : ($flashType === 'error' ? 'error' : '') ?>">
        <?php echo h($flash); ?>
        <?php if ($oneTimeCode !== ''): ?>
          <div class="codebox">Код ученика: <b><?php echo h($oneTimeCode); ?></b></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!is_admin()): ?>
      <div class="card">
        <h2>Вход администратора</h2>
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
          <input type="hidden" name="action" value="admin_login" />
          <input type="password" name="password" placeholder="Пароль" required />
          <button type="submit">Войти</button>
        </form>
        <p class="muted" style="margin:10px 0 0;">Пароль хранится только в приватной конфигурации вне public_html.</p>
      </div>
    <?php else: ?>
      <div class="card">
        <h2>Добавить ученика</h2>
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
          <input type="hidden" name="action" value="add_student" />
          <input name="name" placeholder="Имя" maxlength="80" required />
          <button type="submit">Создать и показать код</button>
        </form>
        <div class="muted" style="margin-top:10px;">Коды хранятся только в виде защищённых хешей. Показ кода — один раз при создании/перевыпуске.</div>
      </div>

      <div class="card">
        <h2>Статистика активности</h2>
        <div class="muted" style="margin:0 0 10px;">События сохраняются в UTC, статистика «сегодня/месяц» считается по московскому времени.</div>
        <div class="stats-wrap">
          <div class="stats-block">
            <div class="stats-title">Сегодня</div>
            <div class="kpi-grid">
              <div class="kpi"><div class="v"><?php echo (int)$summaryToday['login']; ?></div><div class="l">Входы</div></div>
              <div class="kpi"><div class="v"><?php echo (int)$summaryToday['chat']; ?></div><div class="l">Chat</div></div>
              <div class="kpi"><div class="v"><?php echo (int)$summaryToday['stt']; ?></div><div class="l">STT</div></div>
              <div class="kpi"><div class="v"><?php echo (int)$summaryToday['tts']; ?></div><div class="l">TTS</div></div>
            </div>
            <div class="muted" style="margin-top:8px;">Демо: запуски <b><?php echo (int)$demoToday['demo_start']; ?></b> · Chat <b><?php echo (int)$demoToday['demo_chat']; ?></b> · STT <b><?php echo (int)$demoToday['demo_stt']; ?></b> · TTS <b><?php echo (int)$demoToday['demo_tts']; ?></b></div>
          </div>

          <div class="stats-block">
            <div class="stats-title">Текущий месяц</div>
            <div class="kpi-grid">
              <div class="kpi"><div class="v"><?php echo (int)$summaryMonth['login']; ?></div><div class="l">Входы</div></div>
              <div class="kpi"><div class="v"><?php echo (int)$summaryMonth['chat']; ?></div><div class="l">Chat</div></div>
              <div class="kpi"><div class="v"><?php echo (int)$summaryMonth['stt']; ?></div><div class="l">STT</div></div>
              <div class="kpi"><div class="v"><?php echo (int)$summaryMonth['tts']; ?></div><div class="l">TTS</div></div>
            </div>
            <div class="muted" style="margin-top:8px;">Демо: запуски <b><?php echo (int)$demoMonth['demo_start']; ?></b> · Chat <b><?php echo (int)$demoMonth['demo_chat']; ?></b> · STT <b><?php echo (int)$demoMonth['demo_stt']; ?></b> · TTS <b><?php echo (int)$demoMonth['demo_tts']; ?></b></div>
          </div>
        </div>
      </div>

      <div class="card">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Имя</th>
              <th>Статус</th>
              <th>Статистика</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $demoStarts = (int)($demoAll['demo_start'] ?? 0);
              $demoChats = (int)($demoAll['demo_chat'] ?? 0);
              $demoStt = (int)($demoAll['demo_stt'] ?? 0);
              $demoTts = (int)($demoAll['demo_tts'] ?? 0);
              $demoLastUtc = (string)($demoAll['last_utc'] ?? '');
              $demoLastMsk = $demoLastUtc !== '' ? msk_dt($demoLastUtc) : '';
            ?>
            <tr>
              <td>—</td>
              <td><b>Демо‑посетители</b></td>
              <td><span class="badge off">Demo</span></td>
              <td class="muted">
                <div>Запуски демо: <b><?php echo $demoStarts; ?></b></div>
                <div>Последняя активность: <b><?php echo $demoLastMsk !== '' ? h($demoLastMsk) : '—'; ?></b></div>
                <div>Chat: <b><?php echo $demoChats; ?></b> · STT: <b><?php echo $demoStt; ?></b> · TTS: <b><?php echo $demoTts; ?></b></div>
              </td>
              <td class="muted">—</td>
            </tr>

          <?php foreach ($students as $st):
            $id = (int)($st['id'] ?? 0);
            $name = (string)($st['name'] ?? '');
            $active = (int)($st['active'] ?? 0) === 1;
          ?>
            <tr>
              <td><?php echo $id; ?></td>
              <td><?php echo h($name); ?></td>
              <td>
                <?php if ($active): ?>
                  <span class="badge">Active</span>
                <?php else: ?>
                  <span class="badge off">Disabled</span>
                <?php endif; ?>
              </td>
              <td class="muted">
                <?php
                  $loginCount = (int)($st['login_count'] ?? 0);
                  $chatCount = (int)($st['chat_count'] ?? 0);
                  $sttCount = (int)($st['stt_count'] ?? 0);
                  $ttsCount = (int)($st['tts_count'] ?? 0);
                  $lastUtc = (string)($st['last_utc'] ?? '');
                  $lastMsk = $lastUtc !== '' ? msk_dt($lastUtc) : '';
                ?>
                <div>Входы: <b><?php echo $loginCount; ?></b></div>
                <div>Последняя активность: <b><?php echo $lastMsk !== '' ? h($lastMsk) : '—'; ?></b></div>
                <div>Chat: <b><?php echo $chatCount; ?></b> · STT: <b><?php echo $sttCount; ?></b> · TTS: <b><?php echo $ttsCount; ?></b></div>
              </td>
              <td>
                <div class="row">
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
                    <input type="hidden" name="action" value="regen_code" />
                    <input type="hidden" name="id" value="<?php echo $id; ?>" />
                    <button class="ghost" type="submit">Перевыпуск кода</button>
                  </form>

                  <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
                    <input type="hidden" name="action" value="toggle_active" />
                    <input type="hidden" name="id" value="<?php echo $id; ?>" />
                    <input type="hidden" name="active" value="<?php echo ($active ? '0' : '1'); ?>" />
                    <button class="ghost" type="submit"><?php echo $active ? 'Отключить' : 'Включить'; ?></button>
                  </form>

                  <?php if ($deletePendingId === $id && $deletePendingToken !== ''): ?>
                    <div class="muted" style="width:100%;">Удалить ученика «<?php echo h($deletePendingName !== '' ? $deletePendingName : $name); ?>»? Его код доступа и статистика будут безвозвратно удалены.</div>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
                      <input type="hidden" name="action" value="delete_student_confirm" />
                      <input type="hidden" name="id" value="<?php echo $id; ?>" />
                      <input type="hidden" name="token" value="<?php echo h($deletePendingToken); ?>" />
                      <button class="danger" type="submit">Да, удалить</button>
                    </form>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
                      <input type="hidden" name="action" value="delete_student_cancel" />
                      <button class="ghost" type="submit">Отмена</button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
                      <input type="hidden" name="action" value="delete_student_prepare" />
                      <input type="hidden" name="id" value="<?php echo $id; ?>" />
                      <button class="danger" type="submit">Удалить</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>
  </div>

  <?php if ($flash !== ''): ?>
    <script>
      // Auto-hide flash messages to avoid a permanently visible “saved” label.
      setTimeout(function () {
        try {
          var el = document.querySelector('.flash');
          if (el) el.style.display = 'none';
        } catch (e) {}
      }, 4500);
    </script>
  <?php endif; ?>
</body>
</html>
