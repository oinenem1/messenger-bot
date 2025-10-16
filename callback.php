<?php
require 'vendor/autoload.php';

/* ===== DEBUG LOGGER (minimal) ===== */
if (!isset($_GET['showlog'])) {
    file_put_contents('webhook_debug.log', date('c') . " " . $_SERVER['REQUEST_METHOD'] . " " . ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND);
}

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
define("MODEL_TEXT",   "gpt-4o");
define("MODEL_FALLBACK", "gpt-4o-mini");

define("SAVE_IMAGE_PATH", "query_image.jpg");
define("MID_CACHE_FILE",  "mids_cache.json");
define("MID_CACHE_LIMIT", 200);

/* ─────────── UTIL: Message-ID cache to stop repeats ─────────── */
function load_mid_cache() {
    if (!file_exists(MID_CACHE_FILE)) return [];
    $raw = @file_get_contents(MID_CACHE_FILE);
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}
function save_mid_cache($cache) {
    // keep last N
    if (count($cache) > MID_CACHE_LIMIT) {
        $cache = array_slice($cache, -MID_CACHE_LIMIT, MID_CACHE_LIMIT, true);
    }
    @file_put_contents(MID_CACHE_FILE, json_encode($cache));
}
function mid_seen_before($mid) {
    $cache = load_mid_cache();
    return isset($cache[$mid]);
}
function mark_mid_seen($mid) {
    $cache = load_mid_cache();
    $cache[$mid] = time();
    save_mid_cache($cache);
}

/* ─────────── TEXT SANITIZER (keep plain text tidy) ─────────── */
function sanitize_plaintext($s) {
    if (!$s) return $s;
    // collapse huge whitespace and strip duplicate blank lines
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

/* ─────────── HELPER: send to Messenger ─────────── */
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
        $res = curl_exec($ch);
        if ($res === false) {
            file_put_contents('webhook_debug.log', "sendMessengerResponse curl_error: ".curl_error($ch)."\n", FILE_APPEND);
        }
        curl_close($ch);
        usleep(250000);
    }
}

/* ─────────── OPENAI CALL ─────────── */
function callOpenAI($model, $payload) {
    $client = OpenAI::client(OPENAI_KEY);
    try {
        return $client->chat()->create(array_merge(["model" => $model], $payload));
    } catch (Exception $e) {
        file_put_contents("openai_error.log", date('c')." ".$e->getMessage() . "\n", FILE_APPEND);
        // try fallback once if not already using it
        if ($model !== MODEL_FALLBACK) {
            try {
                return $client->chat()->create(array_merge(["model" => MODEL_FALLBACK], $payload));
            } catch (Exception $e2) {
                file_put_contents("openai_error.log", date('c')." Fallback failed: ".$e2->getMessage() . "\n", FILE_APPEND);
                return null;
            }
        }
        return null;
    }
}

/* ─────────── SYSTEM PROMPT (accuracy + no repeats) ─────────── */
$SYSTEM_PROMPT = <<<TXT
You are a careful math/physics tutor.

Deliver exactly ONE solution, no alternate versions, no repeats.

For functions/symmetry:
- Write f(x) exactly as given (piecewise if needed).
- Compute f(-x) from the same definition (show both lines).
- Verdict: EVEN if f(-x)=f(x); ODD if f(-x)=-f(x); else NEITHER.

For linear algebra (eigenvalues/eigenvectors):
- Compute characteristic polynomial carefully.
- Self-check: 
  • trace(A) must equal sum of eigenvalues,
  • det(A) must equal product of eigenvalues.
- If either check fails, re-evaluate and fix before final answer.

For Fourier:
- Prove symmetry using f(-x) from the definition (not intuition).
- Show the integral(s) and main steps clearly, then give the coefficients.

Formatting rules:
- Plain text only (no markdown fences). Short, crisp steps.
- End with "Final answer: ..." on ONE line.
- If the image is partly unreadable, say what is unclear and proceed with what is legible.
TXT;

/* ─────────── TEXT PROCESSING ─────────── */
function getTextResponse($text) {
    global $SYSTEM_PROMPT;
    $payload = [
        "messages" => [
            ["role" => "system", "content" => $SYSTEM_PROMPT],
            ["role" => "user",   "content" => $text]
        ],
        "max_tokens"        => 900,
        "temperature"       => 0.1,
        "top_p"             => 1,
        "frequency_penalty" => 0.4,
        "presence_penalty"  => 0.0
    ];
    $r = callOpenAI(MODEL_TEXT, $payload);
    if (!$r) return "I couldn’t reach the solver right now. Try again in a moment.";
    $out = $r['choices'][0]['message']['content'] ?? null;
    return $out ? sanitize_plaintext($out) : "I didn’t get a response from the solver.";
}

/* ─────────── IMAGE PROCESSING ─────────── */
function getImageResponse() {
    global $SYSTEM_PROMPT;
    $raw = @file_get_contents(SAVE_IMAGE_PATH);
    if (!$raw) return "I couldn't read the image. Please send it again.";
    $b64 = base64_encode($raw);

    $payload = [
        "messages" => [[
            "role" => "system",
            "content" => $SYSTEM_PROMPT
        ], [
            "role" => "user",
            "content" => [
                ["type" => "text", "text" => "Read the image problem carefully, then solve with the rules above."],
                ["type" => "image_url", "image_url" => ["url" => "data:image/jpeg;base64,{$b64}"]]
            ]
        ]],
        "max_tokens"        => 1100,
        "temperature"       => 0.1,
        "top_p"             => 1,
        "frequency_penalty" => 0.4,
        "presence_penalty"  => 0.0
    ];
    $r = callOpenAI(MODEL_VISION, $payload);
    if (!$r) return "I couldn’t reach the vision solver right now. Try again in a moment.";
    $out = $r['choices'][0]['message']['content'] ?? null;
    return $out ? sanitize_plaintext($out) : "I didn’t get a response from the vision solver.";
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

/* ─────────── HANDLE MESSAGES (no repeats, one send) ─────────── */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['entry'])) { http_response_code(200); exit; }

foreach ($input['entry'] as $entry) {
    foreach ($entry['messaging'] ?? [] as $event) {
        if (!empty($event['message']['is_echo'])) continue; // skip echoes

        $psid = $event['sender']['id'] ?? null;
        if (!$psid) continue;

        $mid = $event['message']['mid'] ?? null;
        if ($mid) {
            if (mid_seen_before($mid)) {
                // already handled this exact message
                continue;
            }
            mark_mid_seen($mid);
        }

        // TEXT
        if (isset($event['message']['text'])) {
            $msg = trim($event['message']['text']);
            $reply = getTextResponse($msg);
            sendMessengerResponse($psid, $reply);
        }
        // IMAGE
        elseif (isset($event['message']['attachments'][0]['type']) &&
                $event['message']['attachments'][0]['type'] === 'image') {

            $url = $event['message']['attachments'][0]['payload']['url'] ?? null;
            if ($url) {
                // Try to download the image
                $ok = @file_put_contents(SAVE_IMAGE_PATH, @file_get_contents($url));
                if ($ok === false) {
                    // fallback attempt with curl (some hosts require it)
                    $ch = curl_init($url);
                    $fp = fopen(SAVE_IMAGE_PATH, 'w');
                    curl_setopt_array($ch, [
                        CURLOPT_FILE => $fp,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT => 60,
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                    fclose($fp);
                }
                $reply = getImageResponse();
                sendMessengerResponse($psid, $reply);
                @unlink(SAVE_IMAGE_PATH);
            } else {
                sendMessengerResponse($psid, "I received an image but no valid link. Please resend it clearly.");
            }
        }
        // OTHER
        elseif (isset($event['postback']['payload'])) {
            sendMessengerResponse($psid, "Tapped: ".$event['postback']['payload']);
        }
    }
}

http_response_code(200);
