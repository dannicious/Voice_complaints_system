<?php
// Copy this file to groq_config.php (same folder) and fill in your real
// API key. groq_config.php is git-ignored — it must never be committed,
// since it holds a live credential.
//
// Get a free key at https://console.groq.com/keys (sign in, click
// "Create API Key" — no card required for the free tier).
return [
    'api_key' => 'YOUR_GROQ_API_KEY',
    // Model to call. Chosen for accuracy on this specific task (multilingual
    // complaint triage + spam detection) after comparing a few free-tier
    // Groq models head-to-head - if you change it, re-check that whatever
    // you pick actually catches spam/nonsense text reliably, not just that
    // it responds. If this ever 404s, check https://console.groq.com/docs/models
    // for current model names.
    'model' => 'qwen/qwen3.8-27b',
    // Seconds to wait per attempt before giving up on that attempt. Groq's
    // LPU inference is normally fast (~1-3s observed for this prompt).
    'timeout_seconds' => 12,
    // How many times to try before falling back to the local classifier
    // (which has no spam-detection ability at all). 2 means one retry.
    'max_attempts' => 2,
];
