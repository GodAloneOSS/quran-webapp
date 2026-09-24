<?php
// ask-proxy.php — server-side AI proxy for godalone.in/quran (Quiz + Ask Bayyinah)
//
// SETUP (one time):
//   1) FREE Gemini key at  https://aistudio.google.com/apikey   (required)
//   2) FREE Groq key at    https://console.groq.com/keys        (optional — a backup brain)
//   Paste each key between the quotes on the two lines below, save, and upload this file.
//   The site works with just the Gemini key; Groq is only a safety net for busy days.

$GEMINI_API_KEY = 'AQ.Ab8RN6LkGkaIWfZTly5_2OvbMLOzD5Ua3kH4tOqSWU-zt-k1Qw';
$GROQ_API_KEY   = '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';

// Models
$GEMINI_MODELS = array('gemini-flash-latest', 'gemini-3.5-flash', 'gemini-2.0-flash');
$GROQ_MODEL    = 'llama-3.3-70b-versatile';

error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(array('error' => 'Use POST')); exit; }

$has_gemini = ($GEMINI_API_KEY !== '' && strpos($GEMINI_API_KEY, 'PASTE-') !== 0);
$has_groq   = ($GROQ_API_KEY !== '' && strpos($GROQ_API_KEY, 'PASTE-') !== 0);
if (!$has_gemini && !$has_groq) {
    http_response_code(500);
    echo json_encode(array('error' => 'The AI is not set up yet on this server.'));
    exit;
}

$req = json_decode(file_get_contents('php://input'), true);
if (!$req) { http_response_code(400); echo json_encode(array('error' => 'Bad request')); exit; }

$system = isset($req['system']) ? strval($req['system']) : '';
$messages = isset($req['messages']) && is_array($req['messages']) ? $req['messages'] : array();
$maxTokens = isset($req['maxTokens']) ? intval($req['maxTokens']) : 1000;
if ($maxTokens < 1 || $maxTokens > 4000) $maxTokens = 1000;

function http_post_json($url, $headers, $payload, $timeout) {
    $ctx = stream_context_create(array(
        'http' => array('method' => 'POST', 'header' => $headers, 'content' => $payload,
                        'timeout' => $timeout, 'ignore_errors' => true),
        'ssl'  => array('verify_peer' => false, 'verify_peer_name' => false)
    ));
    return @file_get_contents($url, false, $ctx);
}

$text = '';
$last_err = 'AI unavailable';

// ── PRIMARY: Gemini ──
if ($has_gemini) {
    $contents = array();
    foreach ($messages as $m) {
        $role = (isset($m['role']) && $m['role'] === 'assistant') ? 'model' : 'user';
        $contents[] = array('role' => $role, 'parts' => array(array('text' => isset($m['content']) ? strval($m['content']) : '')));
    }
    $body = array('contents' => $contents,
                  'generationConfig' => array('maxOutputTokens' => $maxTokens, 'temperature' => 0.4));
    if ($system !== '') $body['systemInstruction'] = array('parts' => array(array('text' => $system)));
    $json_body = json_encode($body);

    foreach ($GEMINI_MODELS as $model) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($GEMINI_API_KEY);
        $resp = http_post_json($url, "Content-Type: application/json\r\n", $json_body, 30);
        if ($resp === false) { $last_err = 'Gemini unavailable'; continue; }
        $d = json_decode($resp, true);
        if (isset($d['candidates'][0]['content']['parts']) && is_array($d['candidates'][0]['content']['parts'])) {
            foreach ($d['candidates'][0]['content']['parts'] as $p) {
                if (isset($p['text'])) $text .= $p['text'];
            }
        }
        if ($text !== '') break;                       // success
        if (isset($d['error']['message'])) {
            $last_err = $d['error']['message'];
            $m_low = strtolower($last_err);
            // model unavailable -> try next model; otherwise stop Gemini and try Groq
            if (strpos($m_low, 'not available') === false && strpos($m_low, 'not found') === false
                && strpos($m_low, 'is not supported') === false) break;
        }
    }
}

// ── FALLBACK: Groq (OpenAI-compatible) ──
if ($text === '' && $has_groq) {
    $oa = array();
    if ($system !== '') $oa[] = array('role' => 'system', 'content' => $system);
    foreach ($messages as $m) {
        $oa[] = array('role' => (isset($m['role']) && $m['role'] === 'assistant') ? 'assistant' : 'user',
                      'content' => isset($m['content']) ? strval($m['content']) : '');
    }
    $payload = json_encode(array('model' => $GROQ_MODEL, 'messages' => $oa,
                                 'max_tokens' => $maxTokens, 'temperature' => 0.4));
    $headers = "Content-Type: application/json\r\nAuthorization: Bearer " . $GROQ_API_KEY . "\r\n";
    $resp = http_post_json('https://api.groq.com/openai/v1/chat/completions', $headers, $payload, 30);
    if ($resp !== false) {
        $d = json_decode($resp, true);
        if (isset($d['choices'][0]['message']['content'])) $text = strval($d['choices'][0]['message']['content']);
        if ($text === '' && isset($d['error']['message'])) $last_err = $d['error']['message'];
    }
}

if ($text === '') {
    http_response_code(502);
    echo json_encode(array('error' => $last_err));
    exit;
}
echo json_encode(array('text' => $text));
