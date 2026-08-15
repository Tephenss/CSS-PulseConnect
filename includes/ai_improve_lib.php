<?php
declare(strict_types=1);

/**
 * Shared Gemini/Groq event-description polish for web + mobile BFF.
 * API keys stay server-side (.env). Never expose them to Flutter.
 */

function ai_improve_system_prompt(): string
{
    return "You are an expert event copywriter and AI editor for the College of Computer Studies (CCS). Your job is to POLISH and EXPAND the user's raw notes into an engaging event description while STRICTLY PRESERVING their identity and specific context. "
        . "REQUIREMENTS:\n"
        . "1. IDENTITY PRESERVATION: If the user provides their name or introduces themselves (e.g., 'Hi, I am Mark...'), you MUST retain this in the final output. Polish it into a professional opening (e.g., 'Greetings! I am Mark Stephen Espinosa, and I am pleased to announce...') but NEVER remove the name.\n"
        . "2. DO NOT MENTION PULSECONNECT: You are writing a description for an event. Do NOT mention the system/platform 'PulseConnect' anywhere in your response unless the user explicitly types it in their raw text. Just focus purely on the event itself.\n"
        . "3. INTELLIGENT EXPANSION: Analyze the user's core idea and expand it significantly into a professional, engaging announcement. Add relevant highlights, goals, or 'what to expect' if they fit the context of a university IT event. Do not just use a generic template.\n"
        . "4. FIX & POLISH: Correct typos, mixed Taglish, and grammar. Make the tone sophisticated and exciting but grounded in the user's original intent.\n"
        . "5. CRITICAL LAYOUT: Format the output nicely using multiple short paragraphs. Use standard bullet symbol '•' or dashes '-' for key highlights. Ensure it looks clean and readable.\n"
        . "6. CRITICAL RAW TEXT CONSTRAINT: DO NOT use any Markdown formatting! NO asterisks (**), NO markdown bolding, NO markdown italics. The text will be displayed in a basic HTML textarea, so it must be 100% plain text.\n"
        . "7. Output ONLY the final polished text with no introductory polite phrases (like 'Here is the improved text:').";
}

function ai_improve_key_configured(?string $key, string $placeholderPrefix = 'YOUR_'): bool
{
    $key = trim((string) $key);
    return $key !== '' && !str_starts_with($key, $placeholderPrefix);
}

function ai_improve_apply_ssl($ch): void
{
    if (defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        return;
    }
    apply_curl_ssl_policy($ch);
}

function ai_improve_sanitize_error(string $raw): string
{
    $clean = preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[redacted]', $raw) ?? $raw;
    $clean = preg_replace('/api_key:[^\s\'"]+/i', 'api_key:[redacted]', $clean) ?? $clean;
    $clean = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/', 'Bearer [redacted]', $clean) ?? $clean;
    $clean = preg_replace('/gsk_[A-Za-z0-9]+/', '[redacted]', $clean) ?? $clean;
    $clean = preg_replace('/projects\/\d+/', 'projects/[redacted]', $clean) ?? $clean;
    return $clean;
}

function ai_improve_public_error(int $httpCode, string $body): string
{
    $lower = strtolower($body);
    if (
        $httpCode === 403
        || str_contains($lower, 'consumer_suspended')
        || str_contains($lower, 'has been suspended')
    ) {
        return 'AI formatting is unavailable because the Gemini API key is suspended. Use Raw Text, or add a new GEMINI_API_KEY / GROQ_API_KEY in .env.';
    }
    if (
        $httpCode === 401
        || str_contains($lower, 'api key not valid')
        || str_contains($lower, 'invalid api key')
        || str_contains($lower, 'permission_denied')
    ) {
        return 'AI formatting is unavailable: invalid API key. Update GEMINI_API_KEY or GROQ_API_KEY in .env.';
    }
    if ($httpCode === 429 || $httpCode === 503) {
        return 'AI service is currently busy. Please try again after a few seconds.';
    }
    return 'AI formatting failed. Please use the Raw Text tab or try again.';
}

/**
 * @return array{ok:bool,text?:string,error?:string,fallback?:bool}|null
 */
function ai_improve_via_gemini(string $systemPrompt, string $rawText): ?array
{
    $key = defined('GEMINI_API_KEY') ? (string) GEMINI_API_KEY : '';
    if (!ai_improve_key_configured($key)) {
        return null;
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $key;
    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => $systemPrompt . "\n\nRAW TEXT TO FORMAT:\n" . $rawText]],
        ]],
    ];

    $response = '';
    $httpCode = 0;
    $curlError = '';
    $maxAttempts = 3;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Failed to initialize AI request.', 'fallback' => true];
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        ai_improve_apply_ssl($ch);
        $response = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string) curl_error($ch);
        curl_close($ch);

        if (($httpCode === 503 || $httpCode === 429) && $attempt < $maxAttempts) {
            usleep($attempt * 400000);
            continue;
        }
        break;
    }

    if ($curlError !== '') {
        return [
            'ok' => false,
            'error' => 'AI service is unreachable right now. Please try again in a moment.',
            'fallback' => true,
        ];
    }
    if ($httpCode !== 200) {
        return [
            'ok' => false,
            'error' => ai_improve_public_error($httpCode, $response),
            'fallback' => true,
        ];
    }

    $jsonRes = json_decode($response, true);
    $formattedText = trim((string) ($jsonRes['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    if ($formattedText === '') {
        return ['ok' => false, 'error' => 'Gemini returned an empty response.', 'fallback' => true];
    }
    return ['ok' => true, 'text' => $formattedText];
}

/**
 * @return array{ok:bool,text?:string,error?:string}|null
 */
function ai_improve_via_groq(string $systemPrompt, string $rawText): ?array
{
    $key = defined('GROQ_API_KEY') ? (string) GROQ_API_KEY : '';
    if (!ai_improve_key_configured($key)) {
        return null;
    }

    $models = ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant'];
    $lastError = 'AI formatting failed. Please use the Raw Text tab or try again.';

    foreach ($models as $model) {
        $payload = [
            'model' => $model,
            'temperature' => 0.4,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "RAW TEXT TO FORMAT:\n" . $rawText],
            ],
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        if ($ch === false) {
            continue;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        ai_improve_apply_ssl($ch);
        $response = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string) curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            $lastError = 'AI service is unreachable right now. Please try again in a moment.';
            continue;
        }
        if ($httpCode !== 200) {
            $lastError = ai_improve_public_error($httpCode, $response);
            continue;
        }

        $jsonRes = json_decode($response, true);
        $text = trim((string) ($jsonRes['choices'][0]['message']['content'] ?? ''));
        if ($text !== '') {
            return ['ok' => true, 'text' => $text];
        }
    }

    return ['ok' => false, 'error' => $lastError];
}

/**
 * @return array{ok:bool,improved_text?:string,error?:string,status:int}
 */
function ai_improve_text(string $rawText): array
{
    $rawText = trim($rawText);
    if ($rawText === '') {
        return ['ok' => false, 'error' => 'No text provided.', 'status' => 400];
    }

    $systemPrompt = ai_improve_system_prompt();
    $gemini = ai_improve_via_gemini($systemPrompt, $rawText);
    if (is_array($gemini) && ($gemini['ok'] ?? false) === true) {
        return [
            'ok' => true,
            'improved_text' => (string) $gemini['text'],
            'status' => 200,
        ];
    }

    $groq = ai_improve_via_groq($systemPrompt, $rawText);
    if (is_array($groq) && ($groq['ok'] ?? false) === true) {
        return [
            'ok' => true,
            'improved_text' => (string) $groq['text'],
            'status' => 200,
        ];
    }

    $err = '';
    if (is_array($gemini) && !empty($gemini['error'])) {
        $err = (string) $gemini['error'];
    } elseif (is_array($groq) && !empty($groq['error'])) {
        $err = (string) $groq['error'];
    } else {
        $err = 'AI formatting is not configured. Add GEMINI_API_KEY or GROQ_API_KEY in .env, or use the Raw Text tab.';
    }

    return [
        'ok' => false,
        'error' => ai_improve_sanitize_error($err),
        'status' => 502,
    ];
}
