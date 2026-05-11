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

    'claude_agent' => [
        // The long-lived Anthropic token used by the Claude Agent service.
        // Exposed to the Python Flask container via docker-compose.
        'token' => env('CLAUDE_AGENT_TOKEN'),
        'model' => env('CLAUDE_AGENT_MODEL', 'claude-sonnet-4-5'),
        'max_turns' => (int) env('CLAUDE_AGENT_MAX_TURNS', 12),

        // URL of the Python Flask agent service; set per-environment.
        'service_url' => env('CLAUDE_AGENT_SERVICE_URL', 'http://agent:5000'),

        // Shared secret the agent uses when calling Laravel callbacks,
        // and that Laravel uses when calling the agent.
        'internal_secret' => env('CLAUDE_AGENT_INTERNAL_SECRET'),

        // Public base URL the Flask agent uses to reach Laravel
        // (service name + port inside the docker network).
        'laravel_callback_url' => env('CLAUDE_AGENT_CALLBACK_URL', 'http://app:8000'),
    ],

];
