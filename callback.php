<?php
require 'vendor/autoload.php';

/* ===== DEBUG LOGGER ===== */
file_put_contents('webhook_debug.log', date('c') . " " . $_SERVER['REQUEST_METHOD'] . " " . ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND);
file_put_contents('webhook_debug.log', file_get_contents('php://input') . "\n\n", FILE_APPEND);

/* ===== VIEW LOG ===== */
if (isset($_GET['showlog']) && $_GET['showlog'] === '1') {
    header('Content-Type: text/plain');
    echo file_exists('webhook_debug.log') ? file_get_contents('webhook_debug.log') : 'No log yet.';
    exit;
}

/* ─────────── CONFIG ─────────── */
define("PAGE_ACCESS_TOKEN", getenv('PAGE_ACCESS_TOKEN'));
define("VERIFY_TOKEN", getenv('VERIFY_TOKEN') ?: 'test');
define("OPENAI_KEY", getenv('OPENAI_KEY'));

define("MODEL_VISION", "gpt-4o");
define("MODEL_TEXT", "gpt-4o");
define("MODEL_FALLBACK", "gpt-4o-mini");
define("SAVE_IMAGE_PATH", "query_image.jpg");

/* ─────────── HELPERS ─────────── */
function sanitize_plaintext($s) {
    if (!$s) return $s;
    $s = preg_replace('/\\\\\[|\\\\\]|\\\\\(|\\\\\)/u', '', $s);
    $s = preg_replace('/\$\$?[^$]*\$\$?/u', '', $s);
    $s = preg_replace('/\\\\(?:frac|sum|int|sin|cos|tan|cdot|times|begin|end|pi|alpha|beta|gamma|delta|theta|lambda|mu|nu|rho|sigma|phi|psi|omega)\b/u', '', $s);
    $s = preg_replace('/[ \t]+/u', ' ', $s);
    $s = preg_replace('/\n{3,}/u', "\n\n", $s);
    return trim($s);
}

function chunk_for_messenger($text, $limit = 1800) {
    $text = sanitize_plaintext($text);
    if (mb_strlen($text, 'UTF-8') <= $limit) return [$text];
    $out = [];
    for ($i = 0, $L = mb_strlen($text, 'UTF-8'); $i < $L; $i += $limit)
        $out[] = mb_substr($text, $i, $limit, 'UTF-8');
    return $out;
}

function sendMessengerResponse($psid, $text) {
    $chunks = chunk_for_messenger($text, 1800);
    foreach ($chunks as $chunk) {
        if ($chunk === '') continue;
        $body = [
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['text' => $chunk]
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://graph.facebook.com/v19.0/me/messages?access_token=' . PAGE_ACCESS_TOKEN,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);
        usleep(250000);
    }
}

/* ─────────── OPENAI CALL ─────────── */
function callOpenAI($model, $payload) {
    $client = OpenAI::client(OPENAI_KEY);
    try {
        $res = $client->chat()->create(array_merge(["model" => $model], $payload));
        if (is_object($res)) {
            if (method_exists($res, 'toArray')) $res = $res->toArray();
            else $res = json_decode(json_encode($res), true);
        }
        return $res;
    } catch (Throwable $e) {
        file_put_contents("openai_error.log", date('c') . " | " . $e->getMessage() . "\n", FILE_APPEND);
        return null;
    }
}

/* ─────────── SYSTEM PROMPT ─────────── */
$SYSTEM_PROMPT = <<<TXT
You are a careful math/physics tutor. 
Output must be PLAIN TEXT (no LaTeX, no markdown fences).

Before claiming symmetry, PROVE it:
- Write the function exactly as given (piecewise if needed).
- Compute f(-x) from the same definition.
- Compare:
  f(x) = ...
  f(-x) = ...
- Then state: EVEN (f(-x)=f(x)), ODD (f(-x)=-f(x)), or NEITHER.

Structure every answer like this:
1) Problem: restate briefly.
2) Symmetry check: show f(x) and f(-x) with verdict.
3) Work: numbered clear steps, with integrals or algebra.
4) Final answer: one concise line.

Rules:
- Equations in plain text, e.g. f(x)=x for 0<=x<=pi; f(x)=-x for -pi<=x<0
- No LaTeX commands, no backslashes, no Greek letters—use 'pi' etc.
- Be concise; do NOT repeat the problem or the conclusion twice.
- If the image is blurry, say what is unreadable and proceed logically.
TXT;

/* ─────────── TEXT PROCESSING ─────────── */
function getTextResponse($text) {
    global $SYSTEM_PROMPT;
    $payload = [
        "messages" => [
            ["role" => "system", "content" => $SYSTEM_PROMPT],
            ["role" => "user",   "content" => $text]
        ],
        "max_tokens"  => 700,
        "temperature" => 0.1,
        "top_p"       => 1
    ];
    $r = callOpenAI(MODEL_TEXT, $payload);
    $out = $r['choices'][0]['message']['content'] ?? "(No response)";
    return sanitize_plaintext($out);
}

/* ─────────── IMAGE PROCESSING ─────────── */
function getImageResponse() {
    global $SYSTEM_PROMPT;

    $img = @file_get_contents(SAVE_IMAGE_PATH);
    if ($img === false || strlen($img) === 0) {
        return "I couldn't read the image file. Please send it again.";
    }
    $b64 = base64_encode($img);
    $dataUrl = "data:image/jpeg;base64,{$b64}";

    // Extract problem text
    $extractPayload = [
        "messages" => [
            ["role" => "system", "content" => "Extract ONLY the math/physics problem text from the image as plain text. Do not solve it."],
            [
                "role" => "user",
                "content" => [
                    ["type" => "text", "text" => "Transcribe the problem text exactly."],
                    ["type" => "image_url", "image_url" => ["url" => $dataUrl]]
                ]
            ]
        ],
        "max_tokens"  => 500,
        "temperature" => 0.0
    ];
    $ocr = callOpenAI(MODEL_VISION, $extractPayload);
    $problem = $ocr['choices'][0]['message']['content'] ?? '';
    $problem = sanitize_plaintext($problem);

    if (strlen($problem) < 15) {
        $fallback = [
            "messages" => [
                ["role" => "system", "content" => $SYSTEM_PROMPT],
                [
                    "role" => "user",
                    "content" => [
                        ["type" => "text", "text" => "Solve the problem in this image step-by-step."],
                        ["type" => "image_url", "image_url" => ["url" => $dataUrl]]
                    ]
                ]
            ],
            "max_tokens"  => 900,
            "temperature" => 0.1
        ];
        $one = callOpenAI(MODEL_VISION, $fallback);
        return sanitize_plaintext($one['choices'][0]['message']['content'] ?? "I couldn't process the image.");
    }

    file_put_contents("vision_debug.log", date('c') . "\n" . $problem . "\n\n", FILE_APPEND);

    $solvePayload = [
        "messages" => [
            ["role" => "system", "content" => $SYSTEM_PROMPT],
            ["role" => "user",   "content" => $problem]
        ],
        "max_tokens"  => 900,
        "temperature" => 0.1
    ];
    $sol = callOpenAI(MODEL_TEXT, $solvePayload);
    return sanitize_plaintext($sol['choices'][0]['message']['content'] ?? "(No response)");
}

/* ─────────── VERIFY WEBHOOK ─────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    if ($mode === 'subscribe' && $token === VERIFY_TOKEN) {
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit;
}

/* ─────────── HANDLE MESSAGES (one response only) ─────────── */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['entry'])) exit;

foreach ($input['entry'] as $entry) {
    foreach ($entry['messaging'] ?? [] as $event) {
        if (!empty($event['message']['is_echo'])) continue;

        $psid = $event['sender']['id'] ?? null;
        if (!$psid) continue;

        static $handled_once = false;
        if ($handled_once) break;
        $handled_once = true;

        $mid = $event['message']['mid'] ?? uniqid('nomid_', true);
        static $handled_mids = [];
        if (isset($handled_mids[$mid])) continue;
        $handled_mids[$mid] = true;

        if (!empty($event['message']['text'])) {
            $msg = trim($event['message']['text']);
            $reply = getTextResponse($msg);
            sendMessengerResponse($psid, $reply);
            break;
        }

        if (!empty($event['message']['attachments'][0]['type']) &&
            $event['message']['attachments'][0]['type'] === 'image') {

            $url = $event['message']['attachments'][0]['payload']['url'] ?? null;
            if ($url) {
                file_put_contents(SAVE_IMAGE_PATH, file_get_contents($url));
                $reply = getImageResponse();
                sendMessengerResponse($psid, $reply);
                @unlink(SAVE_IMAGE_PATH);
            } else {
                sendMessengerResponse($psid, "I received an image but no valid link. Please resend it clearly.");
            }
            break;
        }
    }
}

http_response_code(200);
?>
