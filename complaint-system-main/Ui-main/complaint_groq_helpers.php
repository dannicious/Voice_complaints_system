<?php

declare(strict_types=1);

/**
 * Multilingual complaint classification via the Groq API (free tier).
 *
 * Unlike the local Naive Bayes classifier in complaint_ai_helpers.php, this
 * needs no training examples at all — it reads the complaint text directly
 * (English, Tagalog, Bisaya/Cebuano, or a mix of these) and picks the best
 * matching category from whatever is currently active in
 * complaint_categories, plus reports which language it detected and whether
 * the text looks like a genuine complaint attempt at all (vs. spam/nonsense).
 *
 * Groq's API is OpenAI-compatible (chat completions), which is why this
 * looks different from a native Gemini/Anthropic integration.
 *
 * This is intentionally optional and fails soft: if groq_config.php is
 * missing, the key is a placeholder, there's no internet, or the API errors
 * out, groq_classify_text() returns null and the caller falls back to the
 * local classifier (see ai_classify_text_smart() in complaint_ai_helpers.php)
 * so a complaint submission can never be blocked by a third-party outage.
 */

/**
 * Loads groq_config.php. Returns null if the file doesn't exist yet or still
 * has the placeholder key (i.e. nobody has set it up).
 */
function groq_load_config(): ?array
{
    static $config = false; // false = not loaded yet, null = loaded but unusable

    if ($config !== false) {
        return $config;
    }

    $path = __DIR__ . '/groq_config.php';
    if (!is_file($path)) {
        $config = null;
        return $config;
    }

    $loaded = include $path;
    if (!is_array($loaded) || empty($loaded['api_key']) || $loaded['api_key'] === 'YOUR_GROQ_API_KEY') {
        $config = null;
        return $config;
    }

    $config = [
        'api_key' => (string)$loaded['api_key'],
        'model' => (string)($loaded['model'] ?? 'qwen/qwen3.8-27b'),
        'timeout_seconds' => (int)($loaded['timeout_seconds'] ?? 12),
        // Groq's LPU inference is normally fast (typically 1-3s for this
        // prompt), but retry once anyway so a rare transient failure
        // doesn't silently skip the spam check for that submission (the
        // local fallback can't do that check at all).
        'max_attempts' => max(1, (int)($loaded['max_attempts'] ?? 2)),
    ];
    return $config;
}

/**
 * Classifies complaint text against the currently-active category names and
 * detects its language, using the Groq API. Returns null on any failure
 * (not configured, network error, bad response) so the caller can fall back
 * to the local classifier — never throws.
 *
 * Return shape on success:
 *   ['category_id' => int|null, 'category_name' => string|null,
 *    'confidence' => float, 'language' => string, 'source' => 'groq',
 *    'is_spam' => bool]
 */
function groq_classify_text(PDO $pdo, string $text): ?array
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $config = groq_load_config();
    if ($config === null) {
        return null;
    }

    $categories = $pdo->query('SELECT id, name FROM complaint_categories WHERE is_active = 1 ORDER BY name')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (empty($categories)) {
        return null;
    }

    $categoryNames = array_values($categories);
    $categoryListJson = json_encode($categoryNames, JSON_UNESCAPED_UNICODE);

    $prompt = <<<PROMPT
You are triaging a student complaint submitted to a Philippine school. The
complaint text below may be written in English, Tagalog, Bisaya/Cebuano, or a
mix of these (Taglish/Bislish) — read it in whichever language(s) it uses.

Pick the single best-matching category strictly from this list (copy a name
exactly as given, do not invent a new one): {$categoryListJson}

Also identify the primary language of the text: one of "english", "tagalog",
"bisaya", or "mixed" (mixed = a real blend of two or more, not just a
loanword or two).

Also decide whether this is a genuine complaint attempt or spam/nonsense -
e.g. random keyboard mashing, placeholder/test text like "asdf" or "test",
a single word with no real content, or text unrelated to any real incident
(such as a message with no actual incident described, just an unrelated
remark or feeling). Be conservative: only flag is_spam true when it's
clearly not a real complaint attempt. A short, badly-written, or vague
complaint about a real situation is NOT spam - real students write short,
messy, or vague complaints all the time.

Complaint text:
"""
{$text}
"""

Respond with ONLY compact JSON, no other text, in exactly this shape:
{"category":"<one of the given category names, or \"\" if is_spam is true>","language":"english|tagalog|bisaya|mixed","confidence":<0-100 integer>,"is_spam":true|false}
PROMPT;

    $payload = [
        'model' => $config['model'],
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.2,
    ];

    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $response = null;
    for ($attempt = 1; $attempt <= $config['max_attempts']; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['api_key'],
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $config['timeout_seconds'],
            CURLOPT_CONNECTTIMEOUT => min(5, $config['timeout_seconds']),
        ]);
        $attemptResponse = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($attemptResponse !== false && $curlError === '' && $httpCode === 200) {
            $response = $attemptResponse;
            break;
        }
        // Transient (timeout, momentary rate limit, etc.) - worth one more
        // try rather than silently losing the spam check for this
        // submission. A real config problem (bad key, etc.) fails the same
        // way every attempt, so this doesn't waste much time on those.
    }

    if ($response === null) {
        return null;
    }

    $decoded = json_decode($response, true);
    $rawText = $decoded['choices'][0]['message']['content'] ?? null;
    if (!is_string($rawText) || trim($rawText) === '') {
        return null;
    }

    $result = json_decode(trim($rawText), true);
    if (!is_array($result)) {
        return null;
    }

    $isSpam = !empty($result['is_spam']);

    $matchedCategoryId = null;
    $matchedCategoryName = null;
    if (!empty($result['category'])) {
        $wantedName = strtolower(trim((string)$result['category']));
        foreach ($categories as $catId => $catName) {
            if (strtolower(trim($catName)) === $wantedName) {
                $matchedCategoryId = (int)$catId;
                $matchedCategoryName = $catName;
                break;
            }
        }
    }

    if (!$isSpam && $matchedCategoryId === null) {
        // A genuine complaint with no exact category match is a real parse
        // failure - treat it as such so the caller falls back cleanly. When
        // is_spam is true, a missing/unmatched category is expected (the
        // model was told category doesn't matter for spam) and isn't a
        // failure - the spam flag itself is the useful result here.
        return null;
    }

    $language = strtolower(trim((string)($result['language'] ?? '')));
    if (!in_array($language, ['english', 'tagalog', 'bisaya', 'mixed'], true)) {
        $language = 'unknown';
    }

    $confidence = isset($result['confidence']) ? (float)$result['confidence'] : 0.0;
    $confidence = max(0.0, min(100.0, $confidence));

    return [
        'category_id' => $matchedCategoryId,
        'category_name' => $matchedCategoryName,
        'confidence' => round($confidence, 1),
        'language' => $language,
        'source' => 'groq',
        'is_spam' => $isSpam,
    ];
}

/** Human-readable label for a detected language code. */
function groq_language_label(?string $language): string
{
    return match ($language) {
        'english' => 'English',
        'tagalog' => 'Tagalog',
        'bisaya' => 'Bisaya',
        'mixed' => 'Mixed language',
        default => '',
    };
}

/**
 * Ready-to-echo language chip markup, same visual pattern as
 * ai_urgency_chip() in complaint_ai_helpers.php. Returns '' when there's
 * nothing worth showing (unset/unknown - e.g. the local classifier was used
 * as a fallback and never detected a language).
 */
function groq_language_chip(?string $language): string
{
    $label = groq_language_label($language);
    if ($label === '') {
        return '';
    }

    return '<span class="lang-chip">🌐 ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/** Styles for the language chip. Emitted once per page. */
function groq_language_styles(): string
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;

    return '<style>
    .lang-chip { display:inline-block; margin-top:4px; margin-left:4px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px; white-space:nowrap; background:#ede9fe; color:#5b21b6; }
    </style>';
}
