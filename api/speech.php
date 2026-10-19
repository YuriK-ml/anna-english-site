<?php
declare(strict_types=1);

/**
 * Text-to-Speech backend (Stage 4A)
 *
 * Browser (application/json) -> POST /api/speech.php -> OpenAI /v1/audio/speech -> MP3
 *
 * IMPORTANT:
 * - Never expose API keys.
 * - Do not persist generated audio.
 */

// Avoid corrupting binary MP3 output with PHP notices/warnings.
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/_guard.php';

tutor_session_start();

$studentId = isset($_SESSION['tutor_student_id']) ? (int)$_SESSION['tutor_student_id'] : 0;
$isDemo = ($studentId <= 0) && (isset($_SESSION['tutor_demo_active']) && $_SESSION['tutor_demo_active'] === 1);

// Access gate: allow authorized students, and also demo users with a fixed quota.
if ($studentId > 0) {
  tutor_access_gate();
} elseif ($isDemo) {
  if (!isset($_SESSION['tutor_demo_tts_remaining'])) {
    // Stage 6.2C started demo before TTS quota existed.
    $_SESSION['tutor_demo_tts_remaining'] = 5;
  }
  $ttsRemaining = (int)($_SESSION['tutor_demo_tts_remaining'] ?? 0);
  if ($ttsRemaining <= 0) {
    respond_json(403, [
      'error' => [
        'code' => 'demo_limit',
        'message' => 'Бесплатный лимит озвучивания исчерпан. Введите код доступа, чтобы продолжить.',
      ],
    ]);
  }
} else {
  respond_json(401, [
    'error' => [
      'code' => 'auth_required',
      'message' => 'Введите код доступа, чтобы пользоваться озвучиванием.',
    ],
  ]);
}

tutor_rate_limit_or_429('tts', 12, 60, 1500);

function respond_json(int $status, array $payload): void {
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
  }
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function load_openai_config(): void {
  // Same lookup order as api/chat.php and api/transcribe.php
  $envPath = (string)getenv('OPENAI_PRIVATE_CONFIG');
  if ($envPath !== '' && is_file($envPath)) {
    require $envPath;
    return;
  }

  $local = realpath(__DIR__ . '/../openai_config.php');
  if ($local && is_file($local)) {
    require $local;
    return;
  }

  $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
  if ($docRoot !== '') {
    $candidate = realpath($docRoot . '/../private/openai_config.php');
    if ($candidate && is_file($candidate)) {
      require $candidate;
      return;
    }
  }
}

function require_defined(string $name): string {
  if (!defined($name)) {
    respond_json(500, [
      'error' => [
        'message' => 'Сервер не настроен для генерации речи (missing config).',
      ],
    ]);
  }
  $val = constant($name);
  if (!is_string($val) || trim($val) === '') {
    respond_json(500, [
      'error' => [
        'message' => 'Сервер не настроен для генерации речи (invalid config).',
      ],
    ]);
  }
  return $val;
}

function read_json_body_or_400(): array {
  $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  $maxBytes = 12000;
  if ($contentLength > $maxBytes) {
    respond_json(400, [
      'error' => [
        'message' => 'Request is too large.',
      ],
    ]);
  }

  $raw = file_get_contents('php://input');
  if ($raw === false) $raw = '';
  if (strlen($raw) > $maxBytes) {
    respond_json(400, [
      'error' => [
        'message' => 'Request is too large.',
      ],
    ]);
  }

  $data = json_decode($raw ?: 'null', true);
  if (!is_array($data)) {
    respond_json(400, [
      'error' => [
        'message' => 'Invalid JSON.',
      ],
    ]);
  }
  return $data;
}

function string_len(string $s): int {
  if (function_exists('mb_strlen')) return (int)mb_strlen($s, 'UTF-8');
  return strlen($s);
}

function normalize_openai_error(string $model, string $voice, int $status, $raw, string $curlErr): array {
  $type = 'unknown';
  $code = 'unknown';
  $message = 'unknown';

  if (is_string($raw) && $raw !== '') {
    $maybe = json_decode($raw, true);
    if (is_array($maybe) && isset($maybe['error']) && is_array($maybe['error'])) {
      $e = $maybe['error'];
      if (isset($e['type']) && is_string($e['type']) && $e['type'] !== '') $type = $e['type'];
      if (isset($e['code']) && is_string($e['code']) && $e['code'] !== '') $code = $e['code'];
      if (isset($e['message']) && is_string($e['message']) && $e['message'] !== '') $message = $e['message'];
    } else {
      $message = 'non_json_error';
    }
  } else {
    $message = ($curlErr !== '') ? 'curl_exec_failed' : 'empty_response';
  }

  $message = preg_replace('/\s+/', ' ', trim((string)$message));
  if (strlen($message) > 600) $message = substr($message, 0, 600) . '…';

  error_log("OpenAI TTS error: model={$model} voice={$voice} status={$status} type={$type} code={$code} message={$message}");

  return [
    'error' => [
      'message' => 'Speech generation failed.',
    ],
  ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond_json(405, [
    'error' => [
      'message' => 'Only POST is allowed.',
    ],
  ]);
}

$data = read_json_body_or_400();

if (!isset($data['text']) || !is_string($data['text'])) {
  respond_json(400, [
    'error' => [
      'message' => 'Text is required.',
    ],
  ]);
}

$text = trim($data['text']);
if ($text === '') {
  respond_json(400, [
    'error' => [
      'message' => 'Text is empty.',
    ],
  ]);
}

if (string_len($text) > 4096) {
  respond_json(400, [
    'error' => [
      'message' => 'Text is too long.',
    ],
  ]);
}

load_openai_config();
$apiKey = require_defined('OPENAI_API_KEY');
$model = require_defined('OPENAI_TTS_MODEL');
$voice = require_defined('OPENAI_TTS_VOICE');

$url = 'https://api.openai.com/v1/audio/speech';

$payload = [
  'model' => $model,
  'voice' => $voice,
  'input' => $text,
  'instructions' => 'Speak clearly and naturally as an English conversation partner. Use a moderate pace appropriate for an English learner.',
  'response_format' => 'mp3',
];

$ch = curl_init($url);
if ($ch === false) {
  respond_json(500, [
    'error' => [
      'message' => 'Server error.',
    ],
  ]);
}

$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($body) || $body === '') {
  respond_json(500, [
    'error' => [
      'message' => 'Server error.',
    ],
  ]);
}

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $apiKey,
  'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$raw = curl_exec($ch);
$curlErr = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $raw === '' || $status < 200 || $status >= 300) {
  respond_json(502, normalize_openai_error($model, $voice, $status, $raw, (string)$curlErr));
}

// Safety net: if upstream returns JSON instead of audio, convert to our error format.
$trimmedStart = ltrim(substr($raw, 0, 64));
if ($trimmedStart !== '' && ($trimmedStart[0] === '{' || $trimmedStart[0] === '[')) {
  $maybe = json_decode($raw, true);
  if (is_array($maybe) && isset($maybe['error'])) {
    respond_json(502, normalize_openai_error($model, $voice, $status, $raw, ''));
  }
}

// Best-effort stats logging (must not block Tutor).
try {
  $studentId = isset($_SESSION['tutor_student_id']) ? (int)$_SESSION['tutor_student_id'] : 0;
  if ($studentId > 0) {
    $pdo = tutor_pdo();
    tutor_usage_log_success($pdo, $studentId, 'tts');
  }
} catch (Throwable $e) {}

// Best-effort demo stats logging (must not block Tutor).
if ($isDemo) {
  try {
    $pdo = tutor_pdo();
    tutor_usage_log_demo_success($pdo, 'demo_tts', ['model' => $model, 'voice' => $voice]);
  } catch (Throwable $e) {}
}

// Demo quota: count only successful speech generations.
if ($isDemo) {
  $ttsRemaining = isset($_SESSION['tutor_demo_tts_remaining']) ? (int)$_SESSION['tutor_demo_tts_remaining'] : 0;
  if ($ttsRemaining > 0) {
    $_SESSION['tutor_demo_tts_remaining'] = $ttsRemaining - 1;
  }
}

// Return MP3 bytes (do not wrap in JSON).
while (ob_get_level() > 0) {
  @ob_end_clean();
}
http_response_code(200);
header('Content-Type: audio/mpeg');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $raw;
exit;
