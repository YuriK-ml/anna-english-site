<?php
declare(strict_types=1);

// Demo mode start endpoint (Stage 6.2C)
// - Starts a 5-message demo session for unauthenticated visitors.
// - Never resets remaining attempts within the same PHP session.

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../api/_guard.php';

tutor_rate_limit_or_429('demo_start', 30, 60, 400);

tutor_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  tutor_json_respond(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Only POST is allowed.']]);
}

$studentId = isset($_SESSION['tutor_student_id']) ? (int)$_SESSION['tutor_student_id'] : 0;
if ($studentId > 0) {
  // Authorized students must not be switched into demo.
  tutor_json_respond(200, ['ok' => true, 'mode' => 'student']);
}

$active = isset($_SESSION['tutor_demo_active']) && $_SESSION['tutor_demo_active'] === 1;
if ($active) {
  $remaining = isset($_SESSION['tutor_demo_remaining']) ? (int)$_SESSION['tutor_demo_remaining'] : 0;
  // Stage 6.2C already started the demo before STT/TTS were enabled for demo.
  // Initialize additional counters lazily, but never reset them once present.
  if (!isset($_SESSION['tutor_demo_stt_remaining'])) {
    $_SESSION['tutor_demo_stt_remaining'] = 5;
  }
  if (!isset($_SESSION['tutor_demo_tts_remaining'])) {
    $_SESSION['tutor_demo_tts_remaining'] = 5;
  }

  $sttRemaining = (int)($_SESSION['tutor_demo_stt_remaining'] ?? 0);
  $ttsRemaining = (int)($_SESSION['tutor_demo_tts_remaining'] ?? 0);
  $demoId = isset($_SESSION['tutor_demo_id']) && is_string($_SESSION['tutor_demo_id']) ? (string)$_SESSION['tutor_demo_id'] : '';

  tutor_json_respond(200, [
    'ok' => true,
    'mode' => 'demo',
    'started' => false,
    'demoId' => $demoId,
    'remaining' => max(0, $remaining),
    'sttRemaining' => max(0, $sttRemaining),
    'ttsRemaining' => max(0, $ttsRemaining),
  ]);
}

// Start demo exactly once per PHP session.
try { session_regenerate_id(true); } catch (Throwable $e) {}

$_SESSION['tutor_demo_active'] = 1;
$_SESSION['tutor_demo_remaining'] = 5;
$_SESSION['tutor_demo_stt_remaining'] = 5;
$_SESSION['tutor_demo_tts_remaining'] = 5;
$_SESSION['tutor_demo_started_at'] = gmdate('c');
$_SESSION['tutor_demo_id'] = bin2hex(random_bytes(8));

// Best-effort stats logging (must not break demo).
try {
  $pdo = tutor_pdo();
  tutor_usage_log_demo_success($pdo, 'demo_start');
} catch (Throwable $e) {}

tutor_json_respond(200, [
  'ok' => true,
  'mode' => 'demo',
  'started' => true,
  'demoId' => (string)$_SESSION['tutor_demo_id'],
  'remaining' => 5,
  'sttRemaining' => 5,
  'ttsRemaining' => 5,
]);
