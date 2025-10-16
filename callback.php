<?php
require 'vendor/autoload.php';
// ===== DEBUG LOGGER =====
file_put_contents('webhook_debug.log', date('c') . " " . $_SERVER['REQUEST_METHOD'] . " " . ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND);
file_put_contents('webhook_debug.log', file_get_contents('php://input') . "\n\n", FILE_APPEND);
// ===== VIEW LOG (temporary) =====
if (isset($_GET['showlog']) && $_GET['showlog'] === '1') {
    header('Content-Type: text/plain');
    echo file_exists('webhook_debug.log') ? file_get_contents('webhook_debug.log') : 'No log yet.';
    exit;
}

/** ─────────── CONFIG ─────────── **/
define("PAGE_ACCESS_TOKEN", getenv('PAGE_ACCESS_TOKEN'));
define("VERIFY_TOKEN", getenv('VERIFY_TOKEN') ?: 'test');
define("OPENAI_KEY", getenv("OPENAI_KEY"));

// Models
define("MODEL_VISION", "gpt-4o");
define("MODEL_TEXT", "gpt-4o");
define("MODEL_FALLBACK", "gpt-4o-mini"); 
define("SAVE_IMAGE_PATH", "query_image.jpg");

/** ─────────── HELPER: send to Messenger ─────────── **/
function sendMessengerResponse($psid, $text) {
    $chunks = str_split($text, 1800);
    foreach ($chunks as $chunk) {
        $body = [
            'recipient' => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $chunk]
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://graph.facebook.com/v19.0/me/messages?access_token=' . PAGE_ACCESS_TOKEN,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body)
        ]);
        curl_exec($ch);
        curl_close($ch);
        usleep(300000);
    }
}

/** ─────────── OPENAI CALL ─────────── **/
function callOpenAI($model, $payload) {
    $client = OpenAI::client(OPENAI_KEY);
    try {
        return $client->chat()->create(array_merge(["model" => $model], $payload));
    } catch (Exception $e) {
        file_put_contents("openai_error.log", $e->getMessage() . "\n", FILE_APPEND);
        return null;
    }
}

/** ─────────── TEXT PROCESSING ─────────── **/
function getTextResponse($text) {
    $payload = [
        "messages" => [[
            "role" => "system",
            "content" => "You are a careful math/physics tutor. When a problem is given (in text or image): 1) Restate the problem briefly. 2) Determine symmetry/assumptions (odd/even/period, domain) before computing. 3) Derive formulas explicitly (show integrals, integration by parts, determinant expansions, etc.).
4) Keep notation plain text (no LaTeX). Write like: a0 = 2/pi * ∫_0^π f(x) dx.
5) Finish with a short check (parity check, units, plug a simple value).
If the image is blurry, say what you can read and proceed logically."
   ], [
            "role" => "user",
            "content" => $text
        ]],
        "max_tokens" => 1200,
        "temperature" => 0.3
    ];
    $r = callOpenAI(MODEL_TEXT, $payload);
    return $r['choices'][0]['message']['content'] ?? "(No response)";
}

/** ─────────── IMAGE PROCESSING ─────────── **/
function getImageResponse() {
    $b64 = base64_encode(file_get_contents(SAVE_IMAGE_PATH));
    if (!$b64) return "I couldn't read the image. Please send it again.";

    $payload = [
        "messages" => [[
            "role" => "system",
            "content" => "You are a careful math/physics tutor.
When a problem is given (in text or image):
1) Restate the problem briefly.
2) Determine symmetry/assumptions (odd/even/period, domain) before computing.
3) Derive formulas explicitly (show integrals, integration by parts, determinant expansions, etc.).
4) Keep notation plain text (no LaTeX). Write like: a0 = 2/pi * ∫_0^π f(x) dx.
5) Finish with a short check (parity check, units, plug a simple value).
If the image is blurry, say what you can read and proceed logically."
 ], [
            "role" => "user",
            "content" => [
                ["type" => "text", "text" => "Explain or solve the problem shown in this image."],
                ["type" => "image_url", "image_url" => ["url" => "data:image/jpeg;base64,{$b64}"]]
            ]
        ]],
        "max_tokens" => 1500,
        "temperature" => 0.1
    ];

    $r = callOpenAI(MODEL_VISION, $payload);
    $ans = $r['choices'][0]['message']['content'] ?? "(No response)";
    return trim($ans);
}

/** ─────────── VERIFY WEBHOOK ─────────── **/
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

/** ─────────── HANDLE MESSAGES ─────────── **/
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['entry'])) exit;

foreach ($input['entry'] as $entry) {
    foreach ($entry['messaging'] ?? [] as $event) {
        $psid = $event['sender']['id'] ?? null;
        if (!$psid) continue;

        // avoid duplicates
        if (isset($event['message']['mid'])) {
            static $seen = [];
            if (in_array($event['message']['mid'], $seen)) continue;
            $seen[] = $event['message']['mid'];
        }

        // text message
        if (isset($event['message']['text'])) {
            $msg = trim($event['message']['text']);
            $reply = getTextResponse($msg);
            sendMessengerResponse($psid, $reply);
        }

        // image message
        elseif (isset($event['message']['attachments'][0]['type']) &&
                $event['message']['attachments'][0]['type'] === 'image') {

            $url = $event['message']['attachments'][0]['payload']['url'] ?? null;
            if ($url) {
                file_put_contents(SAVE_IMAGE_PATH, file_get_contents($url));
                $reply = getImageResponse();
                sendMessengerResponse($psid, $reply);
                @unlink(SAVE_IMAGE_PATH);
            } else {
                sendMessengerResponse($psid, "I received an image but no valid link to it. Please resend.");
            }
        }
    }
}

http_response_code(200);
?>

