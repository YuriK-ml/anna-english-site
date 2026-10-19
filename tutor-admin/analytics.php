<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../api/_guard.php';

tutor_session_start();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function require_admin(): void {
  if (!isset($_SESSION['tutor_admin']) || $_SESSION['tutor_admin'] !== 1) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
}

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

function ana_private_dir(): string {
  $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
  if ($docRoot !== '') {
    return $docRoot . '/../private';
  }
  return __DIR__ . '/../private';
}

function ana_db_path(): string {
  $privateDir = ana_private_dir();
  return rtrim($privateDir, '/\\') . DIRECTORY_SEPARATOR . 'site_analytics.sqlite';
}

function ana_retention_days(): int {
  return 90;
}

function ana_pdo_or_null(): ?PDO {
  if (!class_exists('PDO')) return null;

  $drivers = [];
  try { $drivers = PDO::getAvailableDrivers(); } catch (Throwable $e) {}
  if (!in_array('sqlite', $drivers, true)) return null;

  $path = ana_db_path();
  if (!is_file($path)) {
    // No data yet.
    return null;
  }

  try {
    $pdo = new PDO('sqlite:' . $path, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 2000;');

    // Ensure visitor_id exists for legacy DBs.
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
      // ignore
    }

    return $pdo;
  } catch (Throwable $e) {
    error_log('Analytics admin: DB open failed: ' . $e->getMessage());
    return null;
  }
}

function msk_range(string $period): array {
  $tz = new DateTimeZone('Europe/Moscow');
  $now = new DateTimeImmutable('now', $tz);
  $today0 = $now->setTime(0, 0, 0);

  if ($period === '7') {
    $start = $today0->modify('-6 days');
  } elseif ($period === '30') {
    $start = $today0->modify('-29 days');
  } else {
    $start = $today0;
  }

  $end = $now;

  $utc = new DateTimeZone('UTC');
  return [
    $start->setTimezone($utc)->format('c'),
    $end->setTimezone($utc)->format('c'),
    $start,
    $end,
  ];
}

function counts(PDO $pdo, string $whereSql, array $params): array {
  $sql = 'SELECT COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS uniq FROM page_events WHERE ' . $whereSql;
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch();
  return [
    'uniq' => (int)($row['uniq'] ?? 0),
    'visits' => (int)($row['visits'] ?? 0),
  ];
}

function article_title(string $path): string {
  $raw = @file_get_contents($path);
  if (!is_string($raw) || $raw === '') return basename($path);

  if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $raw, $m)) {
    $t = trim(strip_tags($m[1]));
    if ($t !== '') return $t;
  }
  if (preg_match('~<title[^>]*>(.*?)</title>~is', $raw, $m2)) {
    $t2 = trim(strip_tags($m2[1]));
    if ($t2 !== '') return $t2;
  }
  return basename($path);
}

require_admin();

$flash = '';
$flashType = 'info';
if (isset($_SESSION['site_analytics_flash']) && is_string($_SESSION['site_analytics_flash'])) {
  $flash = (string)$_SESSION['site_analytics_flash'];
}
unset($_SESSION['site_analytics_flash']);
if (isset($_SESSION['site_analytics_flash_type']) && is_string($_SESSION['site_analytics_flash_type'])) {
  $flashType = (string)$_SESSION['site_analytics_flash_type'];
}
unset($_SESSION['site_analytics_flash_type']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_check_or_403();

  $action = (string)($_POST['action'] ?? '');

  $pdoForWrite = ana_pdo_or_null();
  if (!$pdoForWrite) {
    $_SESSION['site_analytics_flash'] = 'Данных пока нет или база аналитики недоступна.';
    $_SESSION['site_analytics_flash_type'] = 'error';
    header('Location: ' . (string)($_SERVER['REQUEST_URI'] ?? '/tutor-admin/analytics.php'));
    exit;
  }

  try {
    if ($action === 'purge_30' || $action === 'purge_7') {
      $days = $action === 'purge_30' ? 30 : 7;
      $cut = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days');
      $st = $pdoForWrite->prepare('DELETE FROM page_events WHERE created_at < ?');
      $st->execute([$cut->format('c')]);
      $deleted = $st->rowCount();

      $_SESSION['site_analytics_flash'] = $deleted > 0
        ? ('Удалено событий старше ' . $days . ' дней: ' . (int)$deleted)
        : ('Удалять нечего: нет событий старше ' . $days . ' дней.');
      $_SESSION['site_analytics_flash_type'] = $deleted > 0 ? 'ok' : 'info';
    } elseif ($action === 'purge_all') {
      $deleted = (int)$pdoForWrite->exec('DELETE FROM page_events');
      $_SESSION['site_analytics_flash'] = $deleted > 0
        ? ('Удалено событий: ' . (int)$deleted)
        : 'Удалять нечего: таблица пустая.';
      $_SESSION['site_analytics_flash_type'] = $deleted > 0 ? 'ok' : 'info';
    } else {
      $_SESSION['site_analytics_flash'] = 'Неизвестное действие.';
      $_SESSION['site_analytics_flash_type'] = 'error';
    }
  } catch (Throwable $e) {
    error_log('Site analytics purge failed: ' . $e->getMessage());
    $_SESSION['site_analytics_flash'] = 'Не удалось выполнить очистку статистики.';
    $_SESSION['site_analytics_flash_type'] = 'error';
  }

  header('Location: ' . (string)($_SERVER['REQUEST_URI'] ?? '/tutor-admin/analytics.php'));
  exit;
}

$period = (string)($_GET['period'] ?? '1');
if ($period !== '1' && $period !== '7' && $period !== '30') $period = '1';

[$fromUtc, $toUtc, $fromMsk, $toMsk] = msk_range($period);

$pdo = ana_pdo_or_null();

$internalPages = [
  ['id' => '/', 'label' => 'Главная страница'],
  ['id' => '/teacher.html', 'label' => 'О преподавателе'],
  ['id' => '/ai.html', 'label' => 'AI‑приложения (витрина)'],
  ['id' => '/tutor/', 'label' => 'AI English Tutor'],
  ['id' => '/articles/', 'label' => 'Полезные материалы — список'],
];

$targets = [
  ['id' => 'ai_diagnostics', 'label' => 'AI‑диагностика / подбор программы (внешнее)'],
  ['id' => 'ai_chef', 'label' => 'AI‑повар (внешнее приложение)'],
  ['id' => 'progressme_test', 'label' => 'Тест ProgressMe (внешнее)'],
  ['id' => 'progressme_profile', 'label' => 'Профиль преподавателя ProgressMe (внешнее)'],
  ['id' => 'tg_channel', 'label' => 'Telegram‑канал (внешнее)'],
  ['id' => 'tg_contact', 'label' => 'Telegram Анны (внешнее)'],
];

// Articles list (auto)
$articles = [];
$articlesDir = realpath(__DIR__ . '/../articles');
if ($articlesDir && is_dir($articlesDir)) {
  $items = @scandir($articlesDir);
  if (is_array($items)) {
    foreach ($items as $f) {
      if ($f === '.' || $f === '..') continue;
      if ($f === 'index.html') continue;
      if (!preg_match('/\.html$/i', $f)) continue;
      $full = $articlesDir . DIRECTORY_SEPARATOR . $f;
      if (!is_file($full)) continue;
      $articles[] = [
        'id' => '/articles/' . $f,
        'label' => article_title($full),
      ];
    }
  }
}

?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Аналитика сайта — Anna English AI</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#0b1020;color:rgba(255,255,255,0.92);margin:0;padding:28px 14px}
    a{color:#9ad0ff;text-decoration:none}
    a:hover{text-decoration:underline}
    .wrap{max-width:1100px;margin:0 auto}
    .top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}
    .card{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.10);border-radius:12px;padding:14px;margin:12px 0}
    .muted{color:rgba(255,255,255,0.72)}
    .tabs{display:flex;gap:8px;flex-wrap:wrap}
    .tab{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(255,255,255,0.14);border-radius:999px;padding:8px 10px;background:rgba(0,0,0,0.25);color:#fff}
    .tab.is-active{background:linear-gradient(135deg,#3498db,#2c80b4);border-color:transparent}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,0.08);text-align:left;vertical-align:top}
    th{font-size:12px;color:rgba(255,255,255,0.70);font-weight:800;text-transform:uppercase;letter-spacing:0.04em}
    td.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
    .indent{padding-left:18px}

    .btn{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(255,255,255,0.14);border-radius:999px;padding:8px 12px;background:rgba(0,0,0,0.25);color:#fff;cursor:pointer;font:inherit}
    .btn:hover{background:rgba(0,0,0,0.35)}
    .btn:active{transform:translateY(1px)}
    .btn--danger{border-color:rgba(255,90,90,0.35);background:rgba(255,90,90,0.10)}
    .btn--danger:hover{background:rgba(255,90,90,0.16)}
    .mgmt h2{margin:0 0 10px; font-size:16px;}
    .mgmt h3{margin:14px 0 8px; font-size:14px;}
    .row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .status{font-weight:800}
    .status--on{color:#a6f3c1}
    .status--off{color:#ffb3b3}
    .flash{margin-top:10px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,0.14)}
    .flash.ok{background:rgba(66,185,131,0.12);border-color:rgba(66,185,131,0.25)}
    .flash.error{background:rgba(255,90,90,0.10);border-color:rgba(255,90,90,0.25)}
    .flash.info{background:rgba(255,255,255,0.06)}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <h1 style="margin:0 0 6px; font-size:22px;">Аналитика сайта</h1>
        <div class="muted">Период: <?php echo h($fromMsk->format('d.m.Y H:i')); ?> — <?php echo h($toMsk->format('d.m.Y H:i')); ?> (MSK)</div>
        <div class="muted" style="margin-top:6px;">События хранятся в UTC. Срок хранения — до <?php echo (int)ana_retention_days(); ?> дней.</div>
      </div>
      <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
        <a href="/tutor-admin/">← В админку</a>
      </div>
    </div>

    <div class="card mgmt">
      <h2>Управление статистикой</h2>

      <h3>Учёт моих посещений</h3>
      <div class="row">
        <div>
          Статус: <span id="anaSelfStatus" class="status">—</span>
        </div>
        <button id="anaSelfToggle" type="button" class="btn">…</button>
      </div>
      <div class="muted" style="margin-top:8px;">Настройка действует только в этом браузере. На других устройствах её нужно установить отдельно.</div>

      <h3>Очистка статистики</h3>
      <div class="row" style="align-items:flex-start;">
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
          <input type="hidden" name="action" value="purge_30" />
          <button class="btn" type="submit" onclick="return confirm('Удалить всю статистику старше 30 дней? Более свежие записи сохранятся.');">Удалить старше 30 дней</button>
        </form>
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
          <input type="hidden" name="action" value="purge_7" />
          <button class="btn" type="submit" onclick="return confirm('Удалить всю статистику старше 7 дней? Более свежие записи сохранятся.');">Удалить старше 7 дней</button>
        </form>
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
          <input type="hidden" name="action" value="purge_all" />
          <button class="btn btn--danger" type="submit" onclick="return confirm('Удалить ВСЮ накопленную статистику сайта? Это действие нельзя отменить.');">Удалить всю статистику</button>
        </form>
      </div>

      <?php if ($flash !== ''): ?>
        <div class="flash <?php echo h($flashType); ?>"><?php echo h($flash); ?></div>
      <?php endif; ?>
    </div>

    <script>
      (function(){
        'use strict';
        var LS = {
          consent: 'anna_analytics_consent',
          optout: 'anna_analytics_optout',
          id: 'anna_analytics_id'
        };

        function getConsent(){
          try { return String(localStorage.getItem(LS.consent) || ''); } catch(e){ return ''; }
        }
        function isOptOut(){
          try { return String(localStorage.getItem(LS.optout) || '') === '1'; } catch(e){ return false; }
        }

        function setOff(){
          try {
            localStorage.setItem(LS.optout, '1');
            localStorage.setItem(LS.consent, 'deny');
            localStorage.removeItem(LS.id);
          } catch(e) {}
        }

        function setOn(){
          // Admin explicitly enables analytics in this browser.
          try {
            localStorage.removeItem(LS.optout);
            localStorage.setItem(LS.consent, 'allow');
            // Keep existing browser id if present; analytics.js will create one on the next public page view if missing.
          } catch(e) {}
        }

        function isEnabled(){
          return !isOptOut() && getConsent() === 'allow';
        }

        function render(){
          var st = document.getElementById('anaSelfStatus');
          var btn = document.getElementById('anaSelfToggle');
          if (!st || !btn) return;

          var on = isEnabled();
          if (on) {
            st.textContent = 'включён';
            st.className = 'status status--on';
            btn.textContent = 'Отключить учёт';
          } else {
            st.textContent = 'отключён';
            st.className = 'status status--off';
            btn.textContent = 'Включить учёт';
          }
        }

        var btn = document.getElementById('anaSelfToggle');
        if (btn) {
          btn.addEventListener('click', function(){
            if (isEnabled()) {
              setOff();
            } else {
              setOn();
            }
            render();
          });
        }

        render();
      })();
    </script>

    <div class="card">
      <div class="tabs">
        <a class="tab <?php echo $period==='1'?'is-active':''; ?>" href="?period=1">Сегодня</a>
        <a class="tab <?php echo $period==='7'?'is-active':''; ?>" href="?period=7">7 дней</a>
        <a class="tab <?php echo $period==='30'?'is-active':''; ?>" href="?period=30">30 дней</a>
      </div>
      <div class="muted" style="margin-top:10px; line-height:1.45;">
        Уникальные — приблизительное число разных браузеров за период. Посещения — фактические открытия страниц. Для внешних ресурсов считаются только клики по ссылкам на нашем сайте.
      </div>
    </div>

    <?php if (!$pdo): ?>
      <div class="card">
        <b>Данных пока нет.</b>
        <div class="muted" style="margin-top:8px;">Проверьте, что подключён <code>/analytics.js</code> и что папка <code>private</code> доступна для записи на хостинге.</div>
      </div>
    <?php else: ?>

      <div class="card">
        <h2 style="margin:0 0 10px; font-size:16px;">Внутренние страницы (просмотры)</h2>
        <table>
          <thead>
            <tr><th>Раздел / материал</th><th class="num">Уникальные</th><th class="num">Посещения</th></tr>
          </thead>
          <tbody>
            <?php foreach ($internalPages as $p):
              $c = counts($pdo, 'event_type = ? AND page_id = ? AND created_at >= ? AND created_at < ?', ['view', $p['id'], $fromUtc, $toUtc]);
            ?>
              <tr>
                <td><?php echo h($p['label']); ?></td>
                <td class="num"><?php echo (int)$c['uniq']; ?></td>
                <td class="num"><?php echo (int)$c['visits']; ?></td>
              </tr>
            <?php endforeach; ?>

            <?php
              // Articles total (list + individual articles)
              $cAll = counts(
                $pdo,
                'event_type = ? AND page_id LIKE ? AND created_at >= ? AND created_at < ?',
                ['view', '/articles/%', $fromUtc, $toUtc]
              );
            ?>
            <tr>
              <td><b>Полезные материалы — всего (включая статьи)</b></td>
              <td class="num"><?php echo (int)$cAll['uniq']; ?></td>
              <td class="num"><?php echo (int)$cAll['visits']; ?></td>
            </tr>

            <?php foreach ($articles as $a):
              $c = counts($pdo, 'event_type = ? AND page_id = ? AND created_at >= ? AND created_at < ?', ['view', $a['id'], $fromUtc, $toUtc]);
            ?>
              <tr>
                <td class="indent"><?php echo h($a['label']); ?></td>
                <td class="num"><?php echo (int)$c['uniq']; ?></td>
                <td class="num"><?php echo (int)$c['visits']; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h2 style="margin:0 0 10px; font-size:16px;">Внешние направления (клики по ссылкам)</h2>
        <table>
          <thead>
            <tr><th>Направление</th><th class="num">Уникальные</th><th class="num">Клики</th></tr>
          </thead>
          <tbody>
            <?php foreach ($targets as $t):
              $c = counts($pdo, 'event_type = ? AND target = ? AND created_at >= ? AND created_at < ?', ['out_click', $t['id'], $fromUtc, $toUtc]);
            ?>
              <tr>
                <td><?php echo h($t['label']); ?></td>
                <td class="num"><?php echo (int)$c['uniq']; ?></td>
                <td class="num"><?php echo (int)$c['visits']; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>

  </div>
</body>
</html>

