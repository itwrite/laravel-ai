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

    /*
    |----------------------------------------------------------------------
    | Pollinations (Free Image Generation)
    |----------------------------------------------------------------------
    |
    | Free text-to-image API used by App\Services\ImageGenerationService.
    | No API key is required for the anonymous tier.
    |
    */

    'pollinations' => [
        'url' => env('POLLINATIONS_URL', 'https://image.pollinations.ai'),
        'model' => env('POLLINATIONS_MODEL', 'flux'),
    ],

    /*
    |----------------------------------------------------------------------
    | Outbound HTTP Proxy
    |----------------------------------------------------------------------
    |
    | Proxy used by the HTTP client (and thus the AI SDK) for outbound
    | requests. Set HTTP_PROXY to an empty string to disable proxying.
    |
    */

    'http_proxy' => env('HTTP_PROXY', 'http://127.0.0.1:1080'),

];
