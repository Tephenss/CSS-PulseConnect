<?php
declare(strict_types=1);

/**
 * Shared AI "Improve" text rewrite for web + mobile.
 * Provider order: Groq first (default), then Gemini — Gemini keys often get suspended.
 * Override with AI_IMPROVE_PROVIDER=groq|gemini|auto in .env
 */

function ai_improve_system_prompt(string $kind = 'description'): string
{
    if ($kind === 'title') {
        return 'You polish event titles for the College of Computer Studies (CCS). '
            . 'Make the title clear, professional, and concise. Keep the meaning. '
            . 'Return ONLY the improved title as plain text — no quotes, no markdown, no commentary.';
    }

    return 'You are an expert event copywriter and AI editor for the College of Computer Studies (CCS). Your job is to POLISH and EXPAND the user\'s raw notes into an engaging event description while STRICTLY PRESERVING their identity and specific context. '
        . "REQUIREMENTS:\n"
        . "1. IDENTITY PRESERVATION: If the user provides their name or introduces themselves (e.g., 'Hi, I am Mark...'), you MUST retain this in the final output. Polish it into a professional opening (e.g., 'Greetings! I am Mark Stephen Espinosa, and I am pleased to announce...') but NEVER remove the name.\n"
        . "2. DO NOT MENTION PULSECONNECT: You are writing a description for an event. Do NOT mention the system/platform 'PulseConnect' anywhere in your response unless the user explicitly types it in their raw text. Just focus purely on the event itself.\n"
        . "3. INTELLIGENT EXPANSION: Analyze the user's core idea and expand it significantly into a professional, engaging announcement (typically 2–4 short paragraphs plus a short bullet list). Add relevant highlights, goals, or 'what to expect' if they fit the context of a university IT/CCS event. Do not just lightly rephrase one sentence.\n"
        . "4. FIX & POLISH: Correct typos, mixed Taglish, and grammar. Make the tone sophisticated and exciting but grounded in the user's original intent.\n"
        . "5. CRITICAL LAYOUT: Format the output nicely using multiple short paragraphs. Use standard bullet symbol '•' or dashes '-' for key highlights. Ensure it looks clean and readable.\n"
        . "6. CRITICAL RAW TEXT CONSTRAINT: DO NOT use any Markdown formatting! NO asterisks (**), NO markdown bolding, NO markdown italics. The text will be displayed in a basic HTML textarea, so it must be 100% plain text.\n"
        . "7. Output ONLY the final polished text with no introductory polite phrases (like 'Here is the improved text:').";
}

function ai_improve_clean_output(string $text): string
{
    $text = trim($text);
    if (preg_match('/^```(?:\w+)?\s*([\s\S]*?)\s*```$/u', $text, $m)) {
        $text = trim((string) $m[1]);
    }
    $text = preg_replace('/\*\*(.*?)\*\*/u', '$1', $text) ?? $text;
    $text = preg_replace('/__(.*?)__/u', '$1', $text) ?? $text;
    $text = preg_replace('/^\s*#{1,6}\s+/mu', '', $text) ?? $text;
    return trim($text);
}

function ai_improve_public_error(int $httpCode, string $body, string $provider = 'ai'): string
{
    $lower = strtolower($body);
    $label = $provider === 'gemini' ? 'Gemini' : ($provider === 'groq' ? 'Groq' : 'AI');

    if (
        $httpCode === 403
        || str_contains($lower, 'consumer_suspended')
        || str_contains($lower, 'has been suspended')
    ) {
        if ($provider === 'gemini') {
            return 'Gemini API key is suspended. Set a working GROQ_API_KEY (preferred) or a new GEMINI_API_KEY in .env.';
        }
        return $label . ' API key was rejected (HTTP 403). Check GROQ_API_KEY / GEMINI_API_KEY in .env.';
    }

    if ($httpCode === 401 || str_contains($lower, 'api key not valid') || str_contains($lower, 'invalid api key')) {
        return $label . ' API key is invalid. Update GROQ_API_KEY or GEMINI_API_KEY in .env.';
    }

    if ($httpCode === 429 || str_contains($lower, 'rate limit') || str_contains($lower, 'resource_exhausted')) {
        return $label . ' rate limit hit. Wait a moment and try again.';
    }

    if ($httpCode === 0) {
        return 'Could not reach the AI service. Check server outbound HTTPS / SSL.';
    }

    return $label . ' request failed (HTTP ' . $httpCode . '). Try again or use Raw Text.';
}

function ai_improve_is_permanent_failure(int $httpCode, string $body): bool
{
    if (in_array($httpCode, [401, 403], true)) {
        return true;
    }
    $lower = strtolower($body);
    return str_contains($lower, 'consumer_suspended')
        || str_contains($lower, 'has been suspended')
        || str_contains($lower, 'api key not valid')
        || str_contains($lower, 'invalid api key')
        || str_contains($lower, 'permission_denied');
}

/**
 * @return array{ok:bool, text?:string, error?:string, http?:int}
 */
function ai_improve_call_gemini(string $apiKey, string $systemPrompt, string $rawText, int $timeoutSec = 45): array
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);
    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => $systemPrompt . "\n\nRAW TEXT TO FORMAT:\n" . $rawText]],
        ]],
        'generationConfig' => [
            'temperature' => 0.45,
            'maxOutputTokens' => 2048,
        ],
    ];

    $lastHttp = 0;
    $lastBody = '';
    $attempts = 3;
    for ($i = 1; $i <= $attempts; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);
        if (function_exists('apply_curl_ssl_policy')) {
            apply_curl_ssl_policy($ch);
        }
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $lastHttp = $httpCode;
        $lastBody = is_string($response) ? $response : '';

        if ($response === false) {
            $lastBody = $curlErr !== '' ? $curlErr : 'cURL failed';
            if ($i < $attempts) {
                usleep(250000 * $i);
                continue;
            }
            break;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $json = json_decode($lastBody, true);
            $text = ai_improve_clean_output((string) ($json['candidates'][0]['content']['parts'][0]['text'] ?? ''));
            if ($text !== '') {
                return ['ok' => true, 'text' => $text, 'http' => $httpCode];
            }
            return ['ok' => false, 'error' => 'Gemini returned an empty response.', 'http' => $httpCode];
        }

        if (ai_improve_is_permanent_failure($httpCode, $lastBody)) {
            break;
        }

        if ($i < $attempts && ($httpCode === 429 || $httpCode >= 500)) {
            usleep(300000 * $i);
            continue;
        }
        break;
    }

    return [
        'ok' => false,
        'error' => ai_improve_public_error($lastHttp, $lastBody, 'gemini'),
        'http' => $lastHttp,
    ];
}

/**
 * @return array{ok:bool, text?:string, error?:string, http?:int}
 */
function ai_improve_call_groq(string $apiKey, string $systemPrompt, string $rawText, int $timeoutSec = 45): array
{
    $url = 'https://api.groq.com/openai/v1/chat/completions';

    // llama-3.1-8b-instant was shut down 2026-08-16; prefer gpt-oss-20b.
    $model = 'openai/gpt-oss-20b';
    if (defined('GROQ_CHAT_MODEL') && trim((string) GROQ_CHAT_MODEL) !== '') {
        $model = trim((string) GROQ_CHAT_MODEL);
    } elseif (function_exists('get_env_val')) {
        $override = trim((string) get_env_val('GROQ_CHAT_MODEL', ''));
        if ($override !== '') {
            $model = $override;
        }
    }

    $modelsToTry = [$model];
    foreach (['openai/gpt-oss-20b', 'openai/gpt-oss-120b'] as $fallback) {
        if (!in_array($fallback, $modelsToTry, true)) {
            $modelsToTry[] = $fallback;
        }
    }

    $lastHttp = 0;
    $lastBody = '';
    $lastModel = $model;

    foreach ($modelsToTry as $tryModel) {
        $lastModel = $tryModel;
        $payload = [
            'model' => $tryModel,
            'temperature' => 0.45,
            'max_tokens' => 2048,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "RAW TEXT TO FORMAT:\n" . $rawText],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);
        if (function_exists('apply_curl_ssl_policy')) {
            apply_curl_ssl_policy($ch);
        }
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $lastHttp = 0;
            $lastBody = $curlErr !== '' ? $curlErr : 'cURL failed';
            continue;
        }

        $lastHttp = $httpCode;
        $lastBody = (string) $response;

        if ($httpCode >= 200 && $httpCode < 300) {
            $json = json_decode($lastBody, true);
            $text = ai_improve_clean_output((string) ($json['choices'][0]['message']['content'] ?? ''));
            if ($text !== '') {
                return ['ok' => true, 'text' => $text, 'http' => $httpCode];
            }
            return ['ok' => false, 'error' => 'Groq returned an empty response.', 'http' => $httpCode];
        }

        $lower = strtolower($lastBody);
        if (
            $httpCode === 404
            || str_contains($lower, 'model_not_found')
            || str_contains($lower, 'does not exist')
            || str_contains($lower, 'model decommissioned')
        ) {
            continue;
        }

        if (ai_improve_is_permanent_failure($httpCode, $lastBody)) {
            break;
        }

        break;
    }

    if ($lastHttp === 0) {
        return [
            'ok' => false,
            'error' => ai_improve_public_error(0, $lastBody, 'groq'),
            'http' => 0,
        ];
    }

    $err = ai_improve_public_error($lastHttp, $lastBody, 'groq');
    if ($lastHttp === 404) {
        $err = 'Groq chat model is unavailable (' . $lastModel . '). Set GROQ_CHAT_MODEL in .env (e.g. openai/gpt-oss-20b).';
    }

    return [
        'ok' => false,
        'error' => $err,
        'http' => $lastHttp,
    ];
}

/**
 * @return array{ok:bool, improved_text?:string, error?:string, status?:int}
 */
function ai_improve_text(string $rawText, string $kind = 'description', int $timeoutSec = 45): array
{
    $rawText = trim($rawText);
    if ($rawText === '') {
        return ['ok' => false, 'error' => 'Text is required.', 'status' => 400];
    }
    if (mb_strlen($rawText) > 4000) {
        return ['ok' => false, 'error' => 'Text is too long (max 4000 characters).', 'status' => 400];
    }

    $kind = strtolower(trim($kind));
    if (!in_array($kind, ['title', 'description'], true)) {
        $kind = 'description';
    }

    $geminiKey = defined('GEMINI_API_KEY') ? trim((string) GEMINI_API_KEY) : '';
    $groqKey = defined('GROQ_API_KEY') ? trim((string) GROQ_API_KEY) : '';
    if ($geminiKey === '' && $groqKey === '') {
        return [
            'ok' => false,
            'error' => 'AI formatting is not configured. Add GROQ_API_KEY (recommended) or GEMINI_API_KEY in .env.',
            'status' => 503,
        ];
    }

    $systemPrompt = ai_improve_system_prompt($kind);

    $preferred = 'auto';
    if (defined('AI_IMPROVE_PROVIDER')) {
        $preferred = strtolower(trim((string) AI_IMPROVE_PROVIDER));
    } elseif (function_exists('get_env_val')) {
        $preferred = strtolower(trim((string) get_env_val('AI_IMPROVE_PROVIDER', 'auto')));
    }
    if (!in_array($preferred, ['auto', 'groq', 'gemini'], true)) {
        $preferred = 'auto';
    }

    $order = match ($preferred) {
        'gemini' => ['gemini', 'groq'],
        'groq' => ['groq', 'gemini'],
        default => ['groq', 'gemini'],
    };

    $errors = [];
    foreach ($order as $provider) {
        if ($provider === 'groq') {
            if ($groqKey === '') {
                continue;
            }
            $res = ai_improve_call_groq($groqKey, $systemPrompt, $rawText, $timeoutSec);
            if (!empty($res['ok']) && isset($res['text'])) {
                return ['ok' => true, 'improved_text' => (string) $res['text']];
            }
            $errors[] = (string) ($res['error'] ?? 'Groq failed.');
            continue;
        }

        if ($geminiKey === '') {
            continue;
        }
        $res = ai_improve_call_gemini($geminiKey, $systemPrompt, $rawText, $timeoutSec);
        if (!empty($res['ok']) && isset($res['text'])) {
            return ['ok' => true, 'improved_text' => (string) $res['text']];
        }
        $errors[] = (string) ($res['error'] ?? 'Gemini failed.');
    }

    if ($errors === []) {
        return [
            'ok' => false,
            'error' => 'AI formatting is not configured. Add GROQ_API_KEY or GEMINI_API_KEY in .env.',
            'status' => 503,
        ];
    }

    $msg = $errors[count($errors) - 1];
    if (count($errors) > 1) {
        $msg = $errors[0] . ' Also: ' . $errors[1];
        if (mb_strlen($msg) > 280) {
            $msg = $errors[count($errors) - 1];
        }
    }

    return ['ok' => false, 'error' => $msg, 'status' => 502];
}
