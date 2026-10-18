<?php
declare(strict_types=1);

// Do not leak PHP warnings/notices to the client.
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

/**
 * Tutor backend: Browser -> POST /api/chat.php -> OpenAI -> JSON -> Browser
 *
 * IMPORTANT:
 * - Never expose API keys in responses.
 * - Never accept system/developer prompts from the client.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/_guard.php';

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
  $maxBytes = 1500000;
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


function last_user_index(array $history): int {
  for ($i = count($history) - 1; $i >= 0; $i--) {
    if (($history[$i]['role'] ?? '') === 'user') return $i;
  }
  return -1;
}

function validate_image_data_url_or_empty($val): string {
  if (!is_string($val)) return '';
  $val = trim($val);
  if ($val === '') return '';

  // data:image/<type>;base64,...
  if (!preg_match('~^data:(image/(png|jpeg|webp));base64,~', $val, $m)) {
    respond(400, [
      'error' => [
        'code' => 'invalid_image',
        'message' => 'Некорректный формат изображения.',
      ],
    ]);
  }

  // Quick size guard on string itself.
  if (strlen($val) > 2000000) {
    respond(400, [
      'error' => [
        'code' => 'image_too_large',
        'message' => 'Слишком большое изображение.',
      ],
    ]);
  }

  $prefix = $m[0];
  $mime = $m[1];
  $b64 = substr($val, strlen($prefix));
  if ($b64 === '') {
    respond(400, [
      'error' => [
        'code' => 'invalid_image',
        'message' => 'Пустое изображение.',
      ],
    ]);
  }

  $bin = base64_decode($b64, true);
  if ($bin === false || $bin === '') {
    respond(400, [
      'error' => [
        'code' => 'invalid_image',
        'message' => 'Некорректное изображение.',
      ],
    ]);
  }

  $maxBytes = 900 * 1024;
  if (strlen($bin) > $maxBytes) {
    respond(400, [
      'error' => [
        'code' => 'image_too_large',
        'message' => 'Слишком большое изображение.',
      ],
    ]);
  }

  // Signature checks (best-effort)
  if ($mime === 'image/png') {
    if (substr($bin, 0, 8) !== "\x89PNG
\x1a\n") {
      respond(400, ['error' => ['code' => 'invalid_image', 'message' => 'Некорректное PNG изображение.']]);
    }
  } elseif ($mime === 'image/jpeg') {
    if (substr($bin, 0, 2) !== "\xFF\xD8") {
      respond(400, ['error' => ['code' => 'invalid_image', 'message' => 'Некорректное JPEG изображение.']]);
    }
  } elseif ($mime === 'image/webp') {
    if (substr($bin, 0, 4) !== 'RIFF' || substr($bin, 8, 4) !== 'WEBP') {
      respond(400, ['error' => ['code' => 'invalid_image', 'message' => 'Некорректное WEBP изображение.']]);
    }
  }

  return $val;
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
    'gaming' => ['role' => 'a friendly gaming buddy', 'context' => 'a conversation about computer games'],
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

function history_mentions_wordle(array $history): bool {
  $max = 18;
  $count = 0;
  for ($i = count($history) - 1; $i >= 0 && $count < $max; $i--) {
    $m = $history[$i];
    $c = (string)($m['content'] ?? '');
    if ($c === '') continue;
    $t = mb_strtolower($c, 'UTF-8');
    if (strpos($t, 'wordle') !== false || strpos($t, 'вордл') !== false) return true;
    $count++;
  }
  return false;
}

function image_guidance_for_topic(string $topicId): string {
  switch ($topicId) {
    case 'gaming':
      return "IMAGE CONTEXT (Gaming):\n" .
        "- If the image is a game screenshot (Wordle/chess/any other game), use it to keep a natural gaming conversation in English.\n" .
        "- Ask the student questions and help them describe what they see or did.\n" .
        "- If details are unclear, ask clarifying questions; do NOT invent letters/colors/scores.\n";
    case 'hotel':
      return "IMAGE CONTEXT (Hotel):\n" .
        "- The image may show a hotel room, booking details, a bill, or a problem (e.g., broken AC). Use it to role-play talking to reception.\n" .
        "- Offer short, useful phrases the student can say to the staff.\n" .
        "- If there is text on the image and it is not readable, ask the student to type the key lines.\n";
    case 'travel':
      return "IMAGE CONTEXT (Travel):\n" .
        "- The image may show a landmark, ticket, route, sign, or map. Use it to help the student ask/answer questions in English about where to go and what to do.\n" .
        "- Encourage the student to describe the place and their plan.\n";
    case 'restaurant':
    case 'coffee':
      return "IMAGE CONTEXT (Restaurant/Coffee Shop):\n" .
        "- The image may show a menu, food/drinks, or a receipt. Use it to practice ordering and asking about ingredients, price, and preferences.\n" .
        "- Ask what they want to order and confirm details politely.\n";
    case 'airport':
      return "IMAGE CONTEXT (Airport):\n" .
        "- The image may show a boarding pass, signs, gate info, or baggage rules. Use it to role-play questions with airport staff.\n" .
        "- If numbers/terms are unclear, ask the student to read them out or type them.\n";
    case 'job':
      return "IMAGE CONTEXT (Job Interview):\n" .
        "- The image may show a CV/resume, vacancy, or notes. Use it to practice interview talk: skills, experience, strengths, and questions to the interviewer.\n" .
        "- Do not rewrite the whole document; focus on key parts and short speaking practice.\n";
    default:
      return "IMAGE CONTEXT:\n" .
        "- Treat the image as extra context inside the CURRENT selected topic. Do NOT switch the topic based on the image.\n" .
        "- Use it to ask a question or continue the role-play naturally. If unclear, ask the student to describe it.\n";
  }
}

function build_system_prompt(string $topicId, string $level, string $analysisLanguage, string $strictness, bool $hasImage, bool $imageOnly, bool $wordleMode): string {
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

  $imageLine = $hasImage ? "An image may be attached to the last user message. Treat it as additional context inside the CURRENT selected topic (do not switch topics). If the image is unclear, ask clarifying questions and do NOT invent details. In the chat reply: start with exactly one short sentence prefixed with \"Image:\" that describes what you see (extract key words/letters/colors if it is a Wordle-like board), then continue the role-play." : "";

  $imageOnlyLine = $imageOnly ? "If the last user message has no meaningful text (image-only), do NOT invent grammar mistakes. Set hasErrors=false and issues=[]." : "";

  $topicImageLine = $hasImage ? image_guidance_for_topic($topicId) : '';

  $gamingLine = '';
  $wordleLine = '';
  if ($topicId === 'gaming') {
    $gamingLine =
      "GAMING MODE (topic-specific):\n" .
      "- First, ask which game the student wants to talk about.\n" .
      "- Keep the conversation about games natural and friendly. Ask questions and help the student express ideas in English.\n" .
      "- If the student uses Russian words mixed into English, do NOT treat that as a grammar mistake. Help with vocabulary: suggest English equivalents. When there are no real English mistakes, keep hasErrors=false and put the explanation in analysis.positiveFeedback, and a full-English rewrite in analysis.naturalAlternative.\n" .
      "- Do NOT put game strategy/advice inside analysis.issues or analysis.naturalAlternative. Game tips belong only in the chat reply.\n" .
      "- If you do not know a game, ask the student to explain the rules instead of inventing details.\n";
  }
  if ($topicId === 'gaming' && $wordleMode) {
    $wordleLine =
      "WORDLE MODE (only if the student chose Wordle):\n" .
      "- You know Wordle rules: a 5-letter word, 6 attempts, with green/yellow/gray tiles.\n" .
      "- Suggest opening the real Wordle in a separate browser tab.\n" .
      "- Use any previous guesses/constraints mentioned in the conversation and in earlier \"Image:\" descriptions.\n" .
      "- If an image shows a Wordle board, in the required single \"Image:\" sentence include a compact extraction of guesses and tile colors so it can be used as context in future turns.\n" .
      "- Do not invent letters/colors if the image is unclear; ask the student clarifying questions.\n" .
      "- Help the student think of the next guess. Do NOT reveal the final answer unless the student explicitly asks for it.\n" .
      "- Consider repeated letters and position constraints.\n";
  }

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
    $gamingLine .
    $wordleLine .
    $topicImageLine .
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
    "- {$imageLine}\n" .
    "- {$imageOnlyLine}\n" .
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
    // Server-side diagnostics only (never send to frontend).
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
      $message = ($err !== '') ? 'curl_exec_failed' : 'empty_response';
    }

    $message = preg_replace('/\s+/', ' ', trim($message));
    if (is_string($message) && strlen($message) > 600) $message = substr($message, 0, 600) . '…';
    error_log("OpenAI error: model={$model} status={$status} type={$type} code={$code} message={$message}");

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

tutor_session_start();
$studentId = isset($_SESSION['tutor_student_id']) ? (int)$_SESSION['tutor_student_id'] : 0;
$isDemo = false;
if ($studentId > 0) {
  tutor_access_gate();
} else {
  // Stage 6.2C: demo mode (5 free text messages per PHP session)
  $isDemo = isset($_SESSION['tutor_demo_active']) && $_SESSION['tutor_demo_active'] === 1;
  if (!$isDemo) {
    respond(401, ['error' => ['code' => 'auth_required', 'message' => 'Введите код доступа, чтобы пользоваться AI Tutor.']]);
  }
  $remaining = isset($_SESSION['tutor_demo_remaining']) ? (int)$_SESSION['tutor_demo_remaining'] : 0;
  if ($remaining <= 0) {
    respond(403, ['error' => ['code' => 'demo_limit', 'message' => 'Демо-лимит исчерпан. Чтобы продолжить, введите персональный код доступа.']]);
  }
}
tutor_rate_limit_or_429('chat', 20, 60, 900);

load_openai_config();
$apiKey = require_defined('OPENAI_API_KEY');
$model = require_defined('OPENAI_TEXT_MODEL');

$input = read_json_body_or_400();

$allowedTopics = ['restaurant','coffee','supermarket','hotel','airport','job','doctor','taxi','smalltalk','cinema','phone','travel','gaming'];
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
$imageDataUrl = validate_image_data_url_or_empty(pick($input, 'imageDataUrl', ''));
$hasImage = $imageDataUrl !== '';
if ($isDemo && $hasImage) {
  respond(403, ['error' => ['code' => 'demo_text_only', 'message' => 'В демо-режиме доступны только текстовые сообщения.']]);
}
$lastUser = last_user_message($history);
$imageOnly = $hasImage && trim($lastUser) === '[Image]';
if (trim($lastUser) === '' && !$hasImage) {
  respond(400, [
    'error' => [
      'code' => 'empty_message',
      'message' => 'Пустое сообщение.',
    ],
  ]);
}

$maxUserChars = 1000;
if (!$imageOnly && mb_strlen($lastUser, 'UTF-8') > $maxUserChars) {
  respond(400, [
    'error' => [
      'code' => 'message_too_long',
      'message' => 'Слишком длинное сообщение.',
    ],
  ]);
}

$wordleMode = ($topic === 'gaming') ? history_mentions_wordle($history) : false;
$system = build_system_prompt($topic, $level, $analysisLanguage, $strictness, $hasImage, $imageOnly, $wordleMode);
$inputMessages = [];
$lastUserIdx = $hasImage ? last_user_index($history) : -1;
foreach ($history as $i => $m) {
  $content = $m['content'];
  if ($hasImage && $i === $lastUserIdx && ($m['role'] ?? '') === 'user') {
    $text = trim((string)$m['content']);
    $parts = [];
    if (!$imageOnly && $text !== '') {
      $parts[] = ['type' => 'input_text', 'text' => $text];
    }
    if ($imageOnly && $text !== '' && $text !== '[Image]') {
      $parts[] = ['type' => 'input_text', 'text' => $text];
    }
    $parts[] = ['type' => 'input_image', 'image_url' => $imageDataUrl];
    $content = $parts;
  }

  $inputMessages[] = [
    'type' => 'message',
    'role' => $m['role'],
    'content' => $content,
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

// Best-effort stats logging (must not block Tutor).
try {
  if ($isDemo) {
    $remaining = isset($_SESSION['tutor_demo_remaining']) ? (int)$_SESSION['tutor_demo_remaining'] : 0;
    if ($remaining > 0) {
      $_SESSION['tutor_demo_remaining'] = $remaining - 1;
    }
    $pdo = tutor_pdo();
    tutor_usage_log_demo_success($pdo, 'demo_chat', ['model' => $model, 'topic' => $topic, 'level' => $level]);
  } else {
    $studentId = isset($_SESSION['tutor_student_id']) ? (int)$_SESSION['tutor_student_id'] : 0;
    if ($studentId > 0) {
      $pdo = tutor_pdo();
      tutor_usage_log_success($pdo, $studentId, 'chat');
    }
  }
} catch (Throwable $e) {}

respond(200, $normalized);












