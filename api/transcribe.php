<?php
declare(strict_types=1);

/**
 * Speech-to-Text backend (Stage 3A)
 *
 * Browser (multipart/form-data) -> POST /api/transcribe.php -> OpenAI /v1/audio/transcriptions -> JSON
 *
 * IMPORTANT:
 * - Never expose API keys.
 * - Do not persist uploaded audio.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function load_openai_config(): void {
  // Same lookup order as api/chat.php
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
    respond(500, [
      'error' => [
        'message' => 'Сервер не настроен для распознавания речи (missing config).',
      ],
    ]);
  }
  $val = constant($name);
  if (!is_string($val) || trim($val) === '') {
    respond(500, [
      'error' => [
        'message' => 'Сервер не настроен для распознавания речи (invalid config).',
      ],
    ]);
  }
  return $val;
}

function pick_uploaded_file(): ?array {
  if (isset($_FILES['file'])) return $_FILES['file'];
  // If frontend uses a different field name, fallback to the first file.
  foreach ($_FILES as $f) {
    return $f;
  }
  return null;
}

function normalize_openai_error(string $model, int $status, $raw, string $curlErr): array {
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

  error_log("OpenAI STT error: model={$model} status={$status} type={$type} code={$code} message={$message}");

  return [
    'error' => [
      'message' => 'Speech transcription failed.',
      // TEMP DEBUG: remove after Tutor integration testing
      'debug' => [
        'model' => $model,
        'status' => $status,
        'type' => $type,
        'openai_code' => $code,
        'openai_message' => $message,
      ],
    ],
  ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, [
    'error' => [
      'message' => 'Only POST is allowed.',
    ],
  ]);
}

$upload = pick_uploaded_file();
if (!$upload) {
  respond(400, [
    'error' => [
      'message' => 'Audio file is required.',
    ],
  ]);
}

$err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
  respond(400, [
    'error' => [
      'message' => 'Upload failed.',
    ],
  ]);
}

$tmp = (string)($upload['tmp_name'] ?? '');
$name = (string)($upload['name'] ?? 'audio');
$size = (int)($upload['size'] ?? 0);
if ($tmp === '' || !is_file($tmp)) {
  respond(400, [
    'error' => [
      'message' => 'Upload is invalid.',
    ],
  ]);
}
if ($size <= 0) {
  respond(400, [
    'error' => [
      'message' => 'Empty audio file.',
    ],
  ]);
}

$maxBytes = 8 * 1024 * 1024;
if ($size > $maxBytes) {
  respond(400, [
    'error' => [
      'message' => 'Audio file is too large.',
    ],
  ]);
}

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$allowedExt = ['webm', 'ogg', 'wav', 'mp3', 'm4a'];

$providedType = (string)($upload['type'] ?? '');
$allowedMime = [
  'audio/webm',
  'video/webm',
  'audio/ogg',
  'application/ogg',
  'audio/wav',
  'audio/x-wav',
  'audio/mpeg',
  'audio/mp4',
];

// Best-effort MIME detection.
$detectedType = '';
if (function_exists('finfo_open')) {
  $finfo = @finfo_open(FILEINFO_MIME_TYPE);
  if ($finfo) {
    $detectedType = (string)@finfo_file($finfo, $tmp);
    @finfo_close($finfo);
  }
}

$mime = $detectedType !== '' ? $detectedType : $providedType;

$extOk = ($ext !== '' && in_array($ext, $allowedExt, true));
$mimeOk = ($mime !== '' && in_array($mime, $allowedMime, true));
if (!$extOk && !$mimeOk) {
  respond(400, [
    'error' => [
      'message' => 'Unsupported audio format.',
    ],
  ]);
}

load_openai_config();
$apiKey = require_defined('OPENAI_API_KEY');
$model = require_defined('OPENAI_TRANSCRIBE_MODEL');

$url = 'https://api.openai.com/v1/audio/transcriptions';

$cfile = curl_file_create($tmp, $mime !== '' ? $mime : 'application/octet-stream', $name);
$payload = [
  'model' => $model,
  'file' => $cfile,
];

$ch = curl_init($url);
if ($ch === false) {
  respond(500, [
    'error' => [
      'message' => 'Server error.',
    ],
  ]);
}

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);

$raw = curl_exec($ch);
$curlErr = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $raw === '' || $status < 200 || $status >= 300) {
  respond(502, normalize_openai_error($model, $status, $raw, (string)$curlErr));
}

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['text']) || !is_string($data['text'])) {
  respond(502, [
    'error' => [
      'message' => 'Speech transcription failed.',
      // TEMP DEBUG: remove after Tutor integration testing
      'debug' => [
        'model' => $model,
        'status' => $status,
        'type' => 'bad_upstream_format',
        'openai_code' => 'bad_json',
        'openai_message' => 'bad_upstream_format',
      ],
    ],
  ]);
}

respond(200, [
  'text' => trim($data['text']),
]);