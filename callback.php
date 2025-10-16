<?php
require 'vendor/autoload.php';

/* ===== DEBUG LOGGER ===== */
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
define("MODEL_REASONING", "gpt-5-reasoning");
define("MODEL_FALLBACK", "gpt-4o-mini");
define("SAVE_IMAGE_PATH", "query_image.jpg");
define("MID_CACHE_FILE", "mids_cache.json");
define("MID_CACHE_LIMIT", 200);

/* ─────────── MESSAGE CACHE ─────────── */
function load_mid_cache() {
    if (!file_exists(MID_CACHE_FILE)) return [];
    $raw = @file_get_contents(MID_CACHE_FILE);
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}
function save_mid_cache($cache) {
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

/* ─────────── HELPERS ─────────── */
function sanitize_plaintext($s) {
    if (!$s) return $s;
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
            CURLOPT_URL => 'https://graph.facebook.com/v19.0/me/messages?access_token=' . PAGE_ACCESS_TOKEN,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 30,
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
        return $client->chat()->create(array_merge(["model" => $model], $payload));
    } catch (Exception $e) {
        file_put_contents("openai_error.log", date('c')." ".$e->getMessage()."\n", FILE_APPEND);
        if ($model !== MODEL_FALLBACK) {
            try {
                return $client->chat()->create(array_merge(["model" => MODEL_FALLBACK], $payload));
            } catch (Exception $e2) {
                file_put_contents("openai_error.log", date('c')." Fallback failed: ".$e2->getMessage()."\n", FILE_APPEND);
                return null;
            }
        }
        return null;
    }
}

/* ─────────── PROMPTS ─────────── */
$SYSTEM_REASONING = <<<TXT
You are GPT-5-reasoning, a meticulous physics and mathematics tutor.
Solve exactly one version of the problem, fully but concisely.

Before giving final answers:
- For functions: compute f(-x) explicitly and prove even/odd/neither.
- For matrices: verify trace(A)=sum of eigenvalues, det(A)=product of eigenvalues.
- For Fourier series: show the correct coefficient integrals; derive them, don’t guess.
If a contradiction is found, correct your own work before output.

Structure every answer:
1) Problem: brief restatement.
2) Symmetry or setup proof.
3) Work: clear numbered steps.
4) Final answer: single concise line.

Plain text only. No LaTeX or markdown.
TXT;

$SYSTEM_VISION = "Read the image and extract the full math/physics problem text exactly, without solving it.";

/* ─────────── TEXT MODE (direct to GPT-5) ─────────── */
function getTextResponse($text) {
    global $SYSTEM_REASONING;
    $payload = [
        "messages" => [
            ["role" => "system", "content" => $SYSTEM_REASONING],
            ["role" => "user",   "content" => $text]
        ],
        "max_tokens" => 1200,
        "temperature" => 0.1,
        "top_p" => 1,
        "frequency_penalty" => 0.4
    ];
    $r = callOpenAI(MODEL_REASONING, $payload);
    if (!$r) return "⚠️ Solver unavailable — please try again.";
    $out = $r['choices'][0]['message']['content'] ?? '';
    return sanitize_plaintext($out ?: "No response from reasoning model.");
}

/* ─────────── IMAGE → GPT-5 CHAIN ─────────── */
function getImageResponse() {
    global $SYSTEM_VISION, $SYSTEM_REASONING;
    if (!file_exists(SAVE_IMAGE_PATH)) return "I couldn’t read the image. Please send it again.";
    $raw = file_get_contents(SAVE_IMAGE_PATH);
    $b64 = base64_encode($raw);

    // Step 1: OCR/extract problem with GPT-4o
    $vision_payload = [
        "messages" => [
            ["role" => "system", "content" => $SYSTEM_VISION],
            ["role" => "user", "content" => [
                ["type" => "text", "text" => "Extract the complete readable problem text from this image:"],
                ["type" => "image_url", "image_url" => ["url" => "data:image/jpeg;base64,{$b64}"]]
            ]]
        ],
        "max_tokens" => 600,
        "temperature" => 0.1
    ];
    $vision = callOpenAI(MODEL_VISION, $vision_payload);
    $problem_text = trim($vision['choices'][0]['message']['content'] ?? '');

    if (!$problem_text) return "I couldn’t extract text from the image.";

    // Step 2: Solve extracted problem with GPT-5 reasoning
    $reason_payload = [
        "messages" => [
            ["role" => "system", "content" => $SYSTEM_REASONING],
            ["role" => "user", "content" => $problem_text]
        ],
        "max_tokens" => 1200,
        "temperature" => 0.1,
        "frequency_penalty" => 0.4
    ];
    $solve = callOpenAI(MODEL_REASONING, $reason_payload);
    $out = trim($solve['choices'][0]['message']['content'] ?? '');
    if (!$out) return "I read the problem but couldn’t get a response from the solver.";
    return sanitize_plaintext($out);
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

/* ─────────── HANDLE MESSAGES ─────────── */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['entry'])) { http_response_code(200); exit; }

foreach ($input['entry'] as $entry) {
    foreach ($entry['messaging'] ?? [] as $event) {
        if (!empty($event['message']['is_echo'])) continue;

        $psid = $event['sender']['id'] ?? null;
        if (!$psid) continue;

        $mid = $event['message']['mid'] ?? null;
        if ($mid) {
            if (mid_seen_before($mid)) continue;
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
                @file_put_contents(SAVE_IMAGE_PATH, @file_get_contents($url));
                $reply = getImageResponse();
                sendMessengerResponse($psid, $reply);
                @unlink(SAVE_IMAGE_PATH);
            } else {
                sendMessengerResponse($psid, "I received an image but no valid link.");
            }
        }
    }
}

http_response_code(200);
?>
