<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'odds_api' => [
        'key' => env('ODDS_API_KEY'),
    ],

    'codex' => [
        // OpenAI Codex CLI, run in-process via `codex exec`. Auth comes from
        // the ChatGPT Pro OAuth session in $CODEX_HOME (docker-compose mounts
        // the host's ~/.codex there).
        'bin' => env('CODEX_BIN', 'codex'),

        // Blank = use the default from ~/.codex/config.toml.
        'model' => env('CODEX_MODEL', ''),

        'timeout' => (int) env('CODEX_TIMEOUT_SECONDS', 300),
    ],

];
