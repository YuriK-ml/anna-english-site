<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../api/_guard.php';

tutor_session_start();

tutor_rate_limit_or_429('admin', 60, 60, 200);

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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = (string)($_POST['action'] ?? '');

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
      $flash = 'Сохранено.';
      $flashType = 'ok';
    } catch (Throwable $e) {
      error_log('Admin toggle_active failed: ' . $e->getMessage());
      $flash = 'Не удалось изменить доступ.';
      $flashType = 'error';
    }
  } elseif ($action === 'archive_student') {
    csrf_check_or_403();
    require_admin();
    $id = (int)($_POST['id'] ?? 0);
    $now = gmdate('c');
    try {
      $st = $pdo->prepare('UPDATE students SET archived=1, active=0, updated_at=? WHERE id=?');
      $st->execute([$now, $id]);
      $flash = 'Ученик архивирован.';
      $flashType = 'ok';
    } catch (Throwable $e) {
      error_log('Admin archive_student failed: ' . $e->getMessage());
      $flash = 'Не удалось архивировать.';
      $flashType = 'error';
    }
  }
}

// Load list
$students = [];
try {
  $st = $pdo->query('SELECT id, name, active, archived, created_at, updated_at FROM students ORDER BY id DESC');
  $students = $st ? $st->fetchAll() : [];
} catch (Throwable $e) {
  error_log('Admin list failed: ' . $e->getMessage());
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
    @media (max-width: 720px){ th:nth-child(3), td:nth-child(3), th:nth-child(4), td:nth-child(4) { display:none; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <h1>Tutor Admin</h1>
      <a href="/tutor/">← В Tutor</a>
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
        <div class="row" style="justify-content: space-between;">
          <div>
            <h2>Ученики</h2>
            <div class="muted">Коды хранятся только в виде защищённых хешей. Показ кода — один раз при создании/перевыпуске.</div>
          </div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
            <input type="hidden" name="action" value="admin_logout" />
            <button class="ghost" type="submit">Выйти</button>
          </form>
        </div>
      </div>

      <div class="card">
        <h2>Добавить ученика</h2>
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
          <input type="hidden" name="action" value="add_student" />
          <input name="name" placeholder="Имя" maxlength="80" required />
          <button type="submit">Создать и показать код</button>
        </form>
      </div>

      <div class="card">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Имя</th>
              <th>Создан</th>
              <th>Обновлён</th>
              <th>Статус</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($students as $st):
            $id = (int)($st['id'] ?? 0);
            $name = (string)($st['name'] ?? '');
            $active = (int)($st['active'] ?? 0) === 1;
            $archived = (int)($st['archived'] ?? 0) === 1;
          ?>
            <tr>
              <td><?php echo $id; ?></td>
              <td><?php echo h($name); ?></td>
              <td class="muted"><?php echo h((string)($st['created_at'] ?? '')); ?></td>
              <td class="muted"><?php echo h((string)($st['updated_at'] ?? '')); ?></td>
              <td>
                <?php if ($archived): ?>
                  <span class="badge off">Archived</span>
                <?php elseif ($active): ?>
                  <span class="badge">Active</span>
                <?php else: ?>
                  <span class="badge off">Disabled</span>
                <?php endif; ?>
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

                  <form method="post" style="margin:0;" onsubmit="return confirm('Архивировать ученика?');">
                    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
                    <input type="hidden" name="action" value="archive_student" />
                    <input type="hidden" name="id" value="<?php echo $id; ?>" />
                    <button class="danger" type="submit">Архивировать</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>
  </div>
</body>
</html>
