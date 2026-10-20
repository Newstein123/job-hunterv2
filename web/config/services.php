<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Python AI service (ai-service/). Laravel sends the token in X-Service-Token.
    'ai' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('AI_SERVICE_TOKEN'),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 120),
    ],

    // Google OAuth client for connecting Gmail accounts (Google Cloud Console > Credentials).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/email-accounts/google/callback'),
    ],

    'mail_sending' => [
        // Minimum gap between two sends from the same account. 180s = at most 20/hour, the Career Coaching
        // Center figure for an established Gmail account (new accounts: 2-3/hour; set a low daily limit).
        'min_seconds_between_sends' => (int) env('MAIL_MIN_SECONDS_BETWEEN_SENDS', 180),
        // Speculative emails (to companies with no posting) per account per day, within the account's
        // overall daily limit. Cold emails draw more spam reports, so they get a lower cap.
        'speculative_daily_limit' => (int) env('MAIL_SPECULATIVE_DAILY_LIMIT', 20),
    ],

    // USD per 1M tokens, from https://ai.google.dev/gemini-api/docs/pricing (paid tier, checked Oct 2026).
    // Output includes thinking tokens. Used to estimate cost in llm_usages; update when prices change.
    'ai_pricing' => [
        'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
        'gemini-2.5-flash-lite' => ['input' => 0.10, 'output' => 0.40],
        'default' => ['input' => (float) env('AI_PRICE_INPUT', 0.30), 'output' => (float) env('AI_PRICE_OUTPUT', 2.50)],
    ],

];
