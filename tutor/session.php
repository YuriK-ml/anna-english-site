<?php
declare(strict_types=1);

// Session state endpoint (Stage 6.2C)
// Used by Tutor frontend to restore access mode after refresh.

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../api/_guard.php';

tutor_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  tutor_json_respond(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Only GET is allowed.']]);
}

$studentId = isset($_SESSION['tutor_student_id']) ? (int)$_SESSION['tutor_student_id'] : 0;
if ($studentId > 0) {
  tutor_json_respond(200, ['mode' => 'student']);
}

$active = isset($_SESSION['tutor_demo_active']) && $_SESSION['tutor_demo_active'] === 1;
if ($active) {
  $remaining = isset($_SESSION['tutor_demo_remaining']) ? (int)$_SESSION['tutor_demo_remaining'] : 0;
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
    'mode' => 'demo',
    'demoId' => $demoId,
    'remaining' => max(0, $remaining),
    'sttRemaining' => max(0, $sttRemaining),
    'ttsRemaining' => max(0, $ttsRemaining),
  ]);
}

tutor_json_respond(200, ['mode' => 'guest']);
