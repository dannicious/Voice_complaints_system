<?php

declare(strict_types=1);

require_once __DIR__ . '/complaint_groq_helpers.php';

/**
 * AI-assisted complaint triage: category classification (via the Groq API -
 * see complaint_groq_helpers.php, handles English/Tagalog/Bisaya/mixed text
 * and flags spam/nonsense, no training data needed) plus a rule-based
 * urgency scorer (flags how serious a complaint sounds from weighted
 * keywords, fully local).
 *
 * This used to also include a self-built local Naive Bayes classifier as a
 * fallback for when the external API was unavailable. That was removed -
 * if Groq fails, a complaint just gets no AI-assigned category (see
 * ai_classify_text_smart()) rather than a lower-quality local guess.
 */

/**
 * Weighted keywords used for urgency scoring. Deliberately simple and
 * fully explainable - a dean can see exactly which words caused a flag.
 * Tune/extend this list as needed; it's the main thing worth citing/
 * justifying in a thesis appendix.
 */
const AI_URGENCY_KEYWORDS = [
    // Critical - immediate danger to life/safety
    'kill' => 10, 'suicide' => 10, 'weapon' => 10, 'knife' => 10, 'gun' => 10,
    'rape' => 10, 'molest' => 10, 'molested' => 10, 'stab' => 9, 'stabbed' => 9,
    'die' => 6, 'dying' => 6,

    // High - physical violence / explicit threats
    'threat' => 6, 'threatened' => 6, 'threatening' => 6, 'assault' => 7,
    'assaulted' => 7, 'beat' => 6, 'punch' => 5, 'punched' => 5, 'attacked' => 6,

    // Medium - physical contact / fear
    'hit' => 3, 'hits' => 3, 'push' => 2, 'pushed' => 2, 'shove' => 2, 'shoved' => 2,
    'bruise' => 3, 'bruised' => 3, 'scared' => 3, 'afraid' => 3, 'unsafe' => 3,
    'intimidate' => 3, 'intimidated' => 3, 'hurt' => 3,

    // Low - social/emotional harm
    'bully' => 1, 'bullying' => 1, 'bullied' => 1, 'mock' => 1, 'mocking' => 1,
    'humiliate' => 2, 'humiliated' => 2, 'tease' => 1, 'teasing' => 1,
    'exclude' => 1, 'excluded' => 1, 'rumors' => 1, 'harass' => 2, 'harassment' => 2,
];

/** Small stopword list - common words that carry no signal for the urgency scorer. */
const AI_STOPWORDS = [
    'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
    'to', 'of', 'in', 'on', 'at', 'and', 'or', 'but', 'if', 'so', 'not',
    'i', 'my', 'me', 'it', 'its', 'this', 'that', 'these', 'those',
    'with', 'for', 'as', 'by', 'from', 'has', 'have', 'had', 'do', 'does',
    'he', 'she', 'they', 'we', 'you', 'your', 'his', 'her', 'their', 'our',
    'am', 'im', 'will', 'would', 'can', 'could', 'us', 'about', 'into',
];

/**
 * Creates the AI-related complaint columns if missing. Safe to call on
 * every request (guarded so the real work happens once per process).
 */
function ai_ensure_ai_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columns = [
        'ai_suggested_category_id' => 'INT NULL DEFAULT NULL',
        'ai_suggestion_confidence' => 'DECIMAL(5,2) NULL DEFAULT NULL',
        'urgency_score' => 'INT NOT NULL DEFAULT 0',
        'urgency_level' => "ENUM('low','medium','high') NULL DEFAULT NULL",
        'ai_detected_language' => "ENUM('english','tagalog','bisaya','mixed','unknown') NULL DEFAULT NULL",
        // Plain VARCHAR rather than an ENUM so switching AI providers again
        // later never requires a schema change (already happened once:
        // Gemini -> Groq).
        'ai_classification_source' => 'VARCHAR(20) NULL DEFAULT NULL',
    ];
    foreach ($columns as $column => $definition) {
        try {
            $pdo->exec("ALTER TABLE complaints ADD COLUMN IF NOT EXISTS `{$column}` {$definition}");
        } catch (PDOException $e) {
            // Ignore - either already applied or the DB user lacks ALTER rights.
        }
    }

    // ai_classification_source was originally an ENUM('gemini','local') -
    // widen it to VARCHAR on any database created before this was changed
    // (ADD COLUMN IF NOT EXISTS above is a no-op for an already-existing
    // column, so this MODIFY is what actually fixes it on those).
    try {
        $pdo->exec("ALTER TABLE complaints MODIFY COLUMN ai_classification_source VARCHAR(20) NULL DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignore - already widened, or the DB user lacks ALTER rights.
    }
}

/**
 * Splits text into lowercase word tokens, stripping punctuation and
 * dropping stopwords/very short tokens. Used by the urgency scorer.
 */
function ai_tokenize(string $text): array
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? '';
    $words = preg_split('/\s+/', trim($text)) ?: [];

    $words = array_filter(
        $words,
        static fn(string $word): bool => $word !== '' && strlen($word) > 1 && !in_array($word, AI_STOPWORDS, true)
    );

    return array_values($words);
}

/**
 * Entry point for classifying a complaint: uses the multilingual Groq
 * classifier (handles English/Tagalog/Bisaya/mixed text with no training
 * data needed, and flags spam/nonsense). If Groq isn't configured,
 * unreachable, or errors out, this comes back with no category assigned
 * (category_id null, is_spam false - fail open, never block a submission
 * on a third-party outage) rather than a lower-quality local guess.
 *
 * Return shape: ['category_id' => int|null, 'category_name' => string|null,
 * 'confidence' => float, 'language' => string|null, 'source' => string|null,
 * 'is_spam' => bool].
 */
function ai_classify_text_smart(PDO $pdo, string $text): array
{
    ai_ensure_ai_tables($pdo);

    $groqResult = groq_classify_text($pdo, $text);
    if ($groqResult !== null) {
        return $groqResult;
    }

    return [
        'category_id' => null,
        'category_name' => null,
        'confidence' => 0.0,
        'language' => null,
        'source' => null,
        'is_spam' => false,
    ];
}

/**
 * Scores how urgent/serious a piece of text sounds, using the weighted
 * AI_URGENCY_KEYWORDS list. Rule-based on purpose (not learned), so the
 * result is fully explainable - matched_words shows exactly why.
 */
function ai_urgency_score(string $text): array
{
    $words = ai_tokenize($text);
    $score = 0;
    $matched = [];

    foreach ($words as $word) {
        if (isset(AI_URGENCY_KEYWORDS[$word])) {
            $score += AI_URGENCY_KEYWORDS[$word];
            $matched[] = $word;
        }
    }

    if ($score >= 10) {
        $level = 'high';
    } elseif ($score >= 4) {
        $level = 'medium';
    } else {
        $level = 'low';
    }

    return [
        'score' => $score,
        'level' => $level,
        'matched_words' => array_values(array_unique($matched)),
    ];
}

/** Display metadata (emoji/label/CSS class) for an urgency level. */
function ai_urgency_badge_meta(?string $level): array
{
    return match ($level) {
        'high' => ['emoji' => '🔴', 'label' => 'High Urgency', 'class' => 'urgency-high'],
        'medium' => ['emoji' => '🟠', 'label' => 'Medium Urgency', 'class' => 'urgency-medium'],
        'low' => ['emoji' => '🟢', 'label' => 'Low Urgency', 'class' => 'urgency-low'],
        default => ['emoji' => '', 'label' => '', 'class' => ''],
    };
}

/**
 * Ready-to-echo urgency chip markup for a complaint row, using its already
 * stored urgency_level (no recomputation). Pairs with the status badge and
 * age chip. Returns '' for low urgency / unset, so the row stays clean and
 * only flags what actually needs attention.
 */
function ai_urgency_chip(?string $level): string
{
    if ($level === null || $level === '' || $level === 'low') {
        return '';
    }

    $meta = ai_urgency_badge_meta($level);
    if ($meta['label'] === '') {
        return '';
    }

    return '<span class="' . htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8') . '">'
        . $meta['emoji'] . ' ' . htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8')
        . '</span>';
}

/**
 * Styles for the urgency chip. Emitted once per page, same pattern as
 * complaint_age_styles().
 */
function ai_urgency_styles(): string
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;

    return '<style>
    .urgency-high, .urgency-medium, .urgency-low { display:inline-block; margin-top:4px; margin-left:4px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px; white-space:nowrap; }
    .urgency-high { background:#fee2e2; color:#b91c1c; }
    .urgency-medium { background:#fef3c7; color:#b45309; }
    .urgency-low { background:#f3f4f6; color:#6b7280; }
    </style>';
}
