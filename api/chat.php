<?php
declare(strict_types=1);

/**
 * Tutor backend: Browser -> POST /api/chat.php -> OpenAI -> JSON -> Browser
 *
 * IMPORTANT:
 * - Never expose API keys in responses.
 * - Never accept system/developer prompts from the client.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function is_https(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
  return false;
}

function client_ip(): string {
  // Prefer REMOTE_ADDR. Do not blindly trust X-Forwarded-For from the public internet.
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  if ($ip === '') $ip = '0.0.0.0';
  return $ip;
}

function now_ms(): int {
  return (int)floor(microtime(true) * 1000);
}

function rate_limit_or_429(): void {
  $ip = client_ip();
  $key = sha1($ip);
  $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
  $path = $dir . DIRECTORY_SEPARATOR . 'anna_tutor_rl_' . $key . '.json';

  $t = time();
  $windowSeconds = 60;
  $maxPerWindow = 20;
  $minIntervalMs = 900;

  $fp = @fopen($path, 'c+');
  if ($fp === false) return; // fail-open to avoid breaking normal users on restrictive hosts

  try {
    @flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: 'null', true);
    if (!is_array($data)) $data = [];
    if (!isset($data['times']) || !is_array($data['times'])) $data['times'] = [];

    // keep only recent timestamps (seconds)
    $times = [];
    foreach ($data['times'] as $ts) {
      $ts = (int)$ts;
      if ($ts > 0 && ($t - $ts) <= $windowSeconds) $times[] = $ts;
    }

    // min interval check (ms)
    $lastMs = isset($data['last_ms']) ? (int)$data['last_ms'] : 0;
    $deltaMs = now_ms() - $lastMs;
    if ($lastMs > 0 && $deltaMs < $minIntervalMs) {
      respond(429, [
        'error' => [
          'code' => 'rate_limited',
          'message' => 'Слишком часто. Подождите пару секунд и попробуйте снова.',
        ],
      ]);
    }

    if (count($times) >= $maxPerWindow) {
      respond(429, [
        'error' => [
          'code' => 'rate_limited',
          'message' => 'Слишком много запросов. Попробуйте позже.',
        ],
      ]);
    }

    $times[] = $t;
    $data['times'] = $times;
    $data['last_ms'] = now_ms();

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
  } finally {
    @flock($fp, LOCK_UN);
    fclose($fp);
  }
}

function load_openai_config(): void {
  // 1) Recommended: env var path (set via hosting panel or .htaccess SetEnv).
  $envPath = (string)getenv('OPENAI_PRIVATE_CONFIG');
  if ($envPath !== '' && is_file($envPath)) {
    require $envPath;
    return;
  }

  // 2) Local dev fallback: project root openai_config.php (gitignored).
  $local = realpath(__DIR__ . '/../openai_config.php');
  if ($local && is_file($local)) {
    require $local;
    return;
  }

  // 3) Common hosting layout: ../private/openai_config.php relative to DOCUMENT_ROOT.
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
        'code' => 'server_config',
        'message' => 'Сервер не настроен для Tutor (missing config).',
      ],
    ]);
  }
  $val = constant($name);
  if (!is_string($val) || trim($val) === '') {
    respond(500, [
      'error' => [
        'code' => 'server_config',
        'message' => 'Сервер не настроен для Tutor (invalid config).',
      ],
    ]);
  }
  return $val;
}

function read_json_body_or_400(): array {
  $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  $maxBytes = 20000;
  if ($contentLength > $maxBytes) {
    respond(400, [
      'error' => [
        'code' => 'payload_too_large',
        'message' => 'Слишком большой запрос.',
      ],
    ]);
  }

  $raw = file_get_contents('php://input');
  if ($raw === false) $raw = '';
  if (strlen($raw) > $maxBytes) {
    respond(400, [
      'error' => [
        'code' => 'payload_too_large',
        'message' => 'Слишком большой запрос.',
      ],
    ]);
  }

  $data = json_decode($raw ?: 'null', true);
  if (!is_array($data)) {
    respond(400, [
      'error' => [
        'code' => 'invalid_json',
        'message' => 'Некорректный JSON.',
      ],
    ]);
  }
  return $data;
}

function pick(array $arr, string $key, $default = null) {
  return array_key_exists($key, $arr) ? $arr[$key] : $default;
}

function validate_enum(string $value, array $allowed, string $field): string {
  if (!in_array($value, $allowed, true)) {
    respond(400, [
      'error' => [
        'code' => 'invalid_input',
        'message' => 'Некорректное значение: ' . $field,
      ],
    ]);
  }
  return $value;
}

function sanitize_history($history): array {
  if (!is_array($history)) {
    respond(400, [
      'error' => [
        'code' => 'invalid_input',
        'message' => 'Некорректная история.',
      ],
    ]);
  }

  $out = [];
  foreach ($history as $m) {
    if (!is_array($m)) continue;
    $role = (string)($m['role'] ?? '');
    $content = (string)($m['content'] ?? '');
    if ($role !== 'user' && $role !== 'assistant') continue;
    $content = trim($content);
    if ($content === '') continue;
    $out[] = ['role' => $role, 'content' => $content];
  }

  $maxMessages = 24;
  if (count($out) > $maxMessages) {
    // Keep the last N messages (most recent context).
    $out = array_slice($out, -$maxMessages);
  }

  return $out;
}

function last_user_message(array $history): string {
  for ($i = count($history) - 1; $i >= 0; $i--) {
    if (($history[$i]['role'] ?? '') === 'user') {
      return (string)($history[$i]['content'] ?? '');
    }
  }
  return '';
}

function scenario_for_topic(string $topicId): array {
  $scenarios = [
    'restaurant' => ['role' => 'a waiter/waitress', 'context' => 'a restaurant'],
    'coffee' => ['role' => 'a barista', 'context' => 'a coffee shop'],
    'supermarket' => ['role' => 'a cashier or store assistant', 'context' => 'a supermarket'],
    'hotel' => ['role' => 'a hotel receptionist', 'context' => 'a hotel front desk'],
    'airport' => ['role' => 'an airport staff member', 'context' => 'an airport check-in/security area'],
    'job' => ['role' => 'an interviewer', 'context' => 'a job interview'],
    'doctor' => ['role' => 'a doctor/medical staff member (language practice)', 'context' => 'a medical visit'],
    'taxi' => ['role' => 'a taxi driver', 'context' => 'a taxi ride'],
    'smalltalk' => ['role' => 'a friendly conversation partner', 'context' => 'small talk'],
    'cinema' => ['role' => 'a cinema cashier or a friend', 'context' => 'a cinema'],
    'phone' => ['role' => 'a person on the phone', 'context' => 'a phone call'],
    'travel' => ['role' => 'a conversation partner', 'context' => 'travel situations'],
  ];

  if (!isset($scenarios[$topicId])) {
    respond(400, [
      'error' => [
        'code' => 'invalid_input',
        'message' => 'Неизвестная тема.',
      ],
    ]);
  }
  return $scenarios[$topicId];
}

function build_system_prompt(string $topicId, string $level, string $analysisLanguage, string $strictness): string {
  $sc = scenario_for_topic($topicId);

  $levelGuide = [
    'A1' => 'Use very simple words and very short sentences. One idea at a time. Ask short questions.',
    'A2' => 'Use simple everyday language. Keep sentences short. Ask clear questions.',
    'B1' => 'Use natural, medium-complex English. Be supportive and conversational.',
    'B2' => 'Use richer vocabulary and natural constructions, but stay clear.',
    'C1' => 'Use natural advanced English. Idioms only if they fit the context.',
    'C2' => 'Use fully natural English with nuance. Complex constructions only when appropriate.',
  ][$level] ?? 'Use clear English appropriate for the user level.';

  $analysisLangLine = $analysisLanguage === 'en'
    ? 'Write analysis explanations in English.'
    : 'Write analysis explanations in Russian.';

  $strictLine = $strictness === 'friendly'
    ? 'Strictness: friendly (only important errors that block understanding).'
    : ($strictness === 'strict'
      ? 'Strictness: strict (detailed corrections including naturalness, but do not invent mistakes).'
      : 'Strictness: normal (main grammar/vocabulary/naturalness issues).');

  return
    "You are an AI English tutor for a school student.\n" .
    "You are role-playing as {$sc['role']} in {$sc['context']}.\n" .
    "CHAT RULES:\n" .
    "- Reply ONLY in English in the chat reply.\n" .
    "- Do NOT include grammar explanations, corrections, ratings, or teacher commentary in the chat reply.\n" .
    "- Stay in character and keep the dialogue open and natural. Ask follow-up questions when it makes sense.\n" .
    "- Do not end the conversation without a reason.\n" .
    "- Adapt output to CEFR level {$level}: {$levelGuide}\n" .
    "\n" .
    "ANALYSIS RULES:\n" .
    "- Analyze ONLY the LAST user message from the provided history.\n" .
    "- Do NOT analyze pronunciation.\n" .
    "- Return issues as an array of objects with: type, original, corrected, explanation, example.\n" .
    "- original/corrected/example must be in English.\n" .
    "- explanation and positiveFeedback must follow analysisLanguage.\n" .
    "- naturalAlternative (if provided) must be in English.\n" .
    "- If there are no mistakes: hasErrors=false, issues=[], positiveFeedback may be set, naturalAlternative optional.\n" .
    "- {$analysisLangLine}\n" .
    "- {$strictLine}\n" .
    "\n" .
    "OUTPUT FORMAT:\n" .
    "Return ONLY valid JSON with this shape:\n" .
    "{\n" .
    "  \"reply\": \"...\",\n" .
    "  \"analysis\": {\n" .
    "    \"hasErrors\": true|false,\n" .
    "    \"positiveFeedback\": \"...\"|null,\n" .
    "    \"naturalAlternative\": \"...\"|null,\n" .
    "    \"issues\": [\n" .
    "      {\"type\":\"Grammar\",\"original\":\"...\",\"corrected\":\"...\",\"explanation\":\"...\",\"example\":\"...\"}\n" .
    "    ]\n" .
    "  }\n" .
    "}\n";
}

function tutor_response_format_schema(): array {
  return [
    'type' => 'json_schema',
    'json_schema' => [
      'name' => 'tutor_reply',
      'strict' => true,
      'schema' => [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['reply', 'analysis'],
        'properties' => [
          'reply' => ['type' => 'string'],
          'analysis' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['hasErrors', 'positiveFeedback', 'naturalAlternative', 'issues'],
            'properties' => [
              'hasErrors' => ['type' => 'boolean'],
              'positiveFeedback' => ['type' => ['string', 'null']],
              'naturalAlternative' => ['type' => ['string', 'null']],
              'issues' => [
                'type' => 'array',
                'items' => [
                  'type' => 'object',
                  'additionalProperties' => false,
                  'required' => ['type', 'original', 'corrected', 'explanation', 'example'],
                  'properties' => [
                    'type' => ['type' => 'string'],
                    'original' => ['type' => 'string'],
                    'corrected' => ['type' => 'string'],
                    'explanation' => ['type' => 'string'],
                    'example' => ['type' => 'string'],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ],
  ];
}

function openai_responses(string $instructions, array $inputMessages, string $model, string $apiKey): array {
  $base = defined('OPENAI_API_BASE') ? (string)constant('OPENAI_API_BASE') : 'https://api.openai.com/v1';
  $url = rtrim($base, '/') . '/responses';

  $payload = [
    'model' => $model,
    'instructions' => $instructions,
    'input' => $inputMessages,
    'temperature' => 0.6,
    // Prefer fast natural dialog over deep reasoning.
    'reasoning' => [
      'effort' => 'none',
    ],
    // Structured Output (JSON Schema).
    'text' => [
      'format' => tutor_response_format_schema(),
    ],
  ];

  $ch = curl_init($url);
  if ($ch === false) {
    respond(500, ['error' => ['code' => 'internal', 'message' => 'Ошибка сервера.']]);
  }

  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
  ]);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);

  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false || $raw === '' || $status < 200 || $status >= 300) {
    // Do not leak upstream details.
    respond(502, [
      'error' => [
        'code' => 'upstream',
        'message' => 'AI временно недоступен. Попробуйте ещё раз.',
      ],
    ]);
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    respond(502, [
      'error' => [
        'code' => 'upstream',
        'message' => 'AI вернул некорректный ответ. Попробуйте ещё раз.',
      ],
    ]);
  }
  return $data;
}

function extract_response_text(array $openai): string {
  // Some clients expose output_text; server returns output[] with message content blocks.
  if (isset($openai['output_text']) && is_string($openai['output_text'])) {
    return trim((string)$openai['output_text']);
  }

  if (isset($openai['output']) && is_array($openai['output'])) {
    foreach ($openai['output'] as $item) {
      if (!is_array($item)) continue;
      if (($item['type'] ?? '') !== 'message') continue;
      if (($item['role'] ?? '') !== 'assistant') continue;
      $content = $item['content'] ?? null;
      if (is_array($content)) {
        foreach ($content as $part) {
          if (!is_array($part)) continue;
          if (($part['type'] ?? '') === 'output_text' && isset($part['text']) && is_string($part['text'])) {
            return trim((string)$part['text']);
          }
        }
      }
    }
  }

  return '';
}

function try_extract_json_object(string $text): ?array {
  if ($text === '') return null;
  $parsed = json_decode($text, true);
  if (is_array($parsed)) return $parsed;

  $start = strpos($text, '{');
  $end = strrpos($text, '}');
  if ($start === false || $end === false || $end <= $start) return null;
  $slice = substr($text, $start, $end - $start + 1);
  $parsed2 = json_decode($slice, true);
  return is_array($parsed2) ? $parsed2 : null;
}

function normalize_response($obj, string $analysisLanguage): array {
  if (!is_array($obj)) $obj = [];

  $reply = isset($obj['reply']) && is_string($obj['reply']) ? trim($obj['reply']) : '';
  $analysis = isset($obj['analysis']) && is_array($obj['analysis']) ? $obj['analysis'] : [];

  $hasErrors = isset($analysis['hasErrors']) ? (bool)$analysis['hasErrors'] : false;
  $positiveFeedback = (isset($analysis['positiveFeedback']) && is_string($analysis['positiveFeedback']) && trim($analysis['positiveFeedback']) !== '')
    ? trim($analysis['positiveFeedback'])
    : null;
  $naturalAlternative = (isset($analysis['naturalAlternative']) && is_string($analysis['naturalAlternative']) && trim($analysis['naturalAlternative']) !== '')
    ? trim($analysis['naturalAlternative'])
    : null;

  $issues = [];
  if (isset($analysis['issues']) && is_array($analysis['issues'])) {
    foreach ($analysis['issues'] as $it) {
      if (!is_array($it)) continue;
      $type = isset($it['type']) && is_string($it['type']) ? trim($it['type']) : '';
      $original = isset($it['original']) && is_string($it['original']) ? trim($it['original']) : '';
      $corrected = isset($it['corrected']) && is_string($it['corrected']) ? trim($it['corrected']) : '';
      $explanation = isset($it['explanation']) && is_string($it['explanation']) ? trim($it['explanation']) : '';
      $example = isset($it['example']) && is_string($it['example']) ? trim($it['example']) : '';
      if ($type === '' || $original === '' || $corrected === '' || $explanation === '' || $example === '') continue;
      $issues[] = [
        'type' => $type,
        'original' => $original,
        'corrected' => $corrected,
        'explanation' => $explanation,
        'example' => $example,
      ];
    }
  }

  if (count($issues) === 0) {
    $hasErrors = false;
  }

  if ($reply === '') {
    // Fallback: never return empty reply to the UI.
    $reply = 'Sorry — could you say that again?';
  }

  return [
    'reply' => $reply,
    'analysis' => [
      'hasErrors' => $hasErrors,
      'positiveFeedback' => $hasErrors
        ? null
        : ($positiveFeedback ?? ($analysisLanguage === 'ru' ? 'Отлично! Ошибок не найдено.' : 'Excellent! No mistakes found.')),
      'naturalAlternative' => $naturalAlternative,
      'issues' => $issues,
    ],
  ];
}

// -------------------------
// Main
// -------------------------

if (!is_https()) {
  // Not mandatory, but helps prevent mixed-content/caching oddities on some hosts.
  // Do not block local dev if behind a proxy that doesn't set headers properly.
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, [
    'error' => [
      'code' => 'method_not_allowed',
      'message' => 'Only POST is allowed.',
    ],
  ]);
}

rate_limit_or_429();

load_openai_config();
$apiKey = require_defined('OPENAI_API_KEY');
$model = require_defined('OPENAI_TEXT_MODEL');

$input = read_json_body_or_400();

$allowedTopics = ['restaurant','coffee','supermarket','hotel','airport','job','doctor','taxi','smalltalk','cinema','phone','travel'];
$allowedLevels = ['A1','A2','B1','B2','C1','C2'];
$allowedLang = ['ru','en'];
$allowedStrict = ['friendly','normal','strict'];

$topic = (string)pick($input, 'topic', '');
$level = (string)pick($input, 'level', '');
$analysisLanguage = (string)pick($input, 'analysisLanguage', 'ru');
$strictness = (string)pick($input, 'strictness', 'normal');

$topic = validate_enum($topic, $allowedTopics, 'topic');
$level = validate_enum($level, $allowedLevels, 'level');
$analysisLanguage = validate_enum($analysisLanguage, $allowedLang, 'analysisLanguage');
$strictness = validate_enum($strictness, $allowedStrict, 'strictness');

$history = sanitize_history(pick($input, 'history', []));
$lastUser = last_user_message($history);
if (trim($lastUser) === '') {
  respond(400, [
    'error' => [
      'code' => 'empty_message',
      'message' => 'Пустое сообщение.',
    ],
  ]);
}

$maxUserChars = 1000;
if (mb_strlen($lastUser, 'UTF-8') > $maxUserChars) {
  respond(400, [
    'error' => [
      'code' => 'message_too_long',
      'message' => 'Слишком длинное сообщение.',
    ],
  ]);
}

$system = build_system_prompt($topic, $level, $analysisLanguage, $strictness);
$inputMessages = [];
foreach ($history as $m) {
  $inputMessages[] = [
    'type' => 'message',
    'role' => $m['role'],
    'content' => $m['content'],
  ];
}

$openai = openai_responses($system, $inputMessages, $model, $apiKey);
$text = extract_response_text($openai);
$obj = try_extract_json_object($text);
if ($obj === null) {
  respond(502, [
    'error' => [
      'code' => 'bad_ai_output',
      'message' => 'AI вернул неожиданный формат. Попробуйте ещё раз.',
    ],
  ]);
}

$normalized = normalize_response($obj, $analysisLanguage);
respond(200, $normalized);
