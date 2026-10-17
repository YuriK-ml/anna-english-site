<?php
declare(strict_types=1);

// Student code auth endpoint.
// Sets a secure PHP session so Tutor API endpoints can be used.

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../api/_guard.php';

tutor_rate_limit_or_429('student_auth', 18, 60, 800);

tutor_session_start();

function respond(int $status, array $payload): void {
  tutor_json_respond($status, $payload);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Only POST is allowed.']]);
}

$raw = file_get_contents('php://input');
if ($raw === false) $raw = '';

$code = '';
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if (stripos($contentType, 'application/json') !== false) {
  $data = json_decode($raw ?: 'null', true);
  if (is_array($data) && isset($data['code']) && is_string($data['code'])) {
    $code = trim($data['code']);
  }
} else {
  if (isset($_POST['code']) && is_string($_POST['code'])) {
    $code = trim($_POST['code']);
  }
}

if ($code === '' || strlen($code) < 6 || strlen($code) > 64) {
  respond(400, ['error' => ['code' => 'invalid_input', 'message' => 'Введите корректный код доступа.']]);
}

// Basic format hardening (allow digits/letters and - _)
if (!preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
  respond(400, ['error' => ['code' => 'invalid_input', 'message' => 'Введите корректный код доступа.']]);
}

$pdo = tutor_pdo();
$lookup = tutor_code_lookup($code);

try {
  $st = $pdo->prepare('SELECT id, name, active, archived FROM students WHERE code_lookup = ? LIMIT 1');
  $st->execute([$lookup]);
  $row = $st->fetch();
} catch (Throwable $e) {
  error_log('Tutor auth: lookup failed: ' . $e->getMessage());
  respond(500, ['error' => ['code' => 'server_error', 'message' => 'Server error.']]);
}

if (!$row || (int)$row['archived'] === 1 || (int)$row['active'] !== 1) {
  respond(401, ['error' => ['code' => 'invalid_code', 'message' => 'Неверный код доступа.']]);
}

// Successful login
try {
  session_regenerate_id(true);
} catch (Throwable $e) {
}

$_SESSION['tutor_student_id'] = (int)$row['id'];
$_SESSION['tutor_student_name'] = (string)$row['name'];

// Best-effort stats logging (must not block auth).
try {
  tutor_usage_log_success($pdo, (int)$row['id'], 'login');
} catch (Throwable $e) {}

respond(200, [
  'ok' => true,
  'student' => [
    'id' => (int)$row['id'],
    'name' => (string)$row['name'],
  ],
]);
