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
