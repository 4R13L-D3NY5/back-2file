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

    'university' => [
        'base_url' => env('UNIVERSITY_BASE_URL', 'https://gw-dev.unitepc.solutions/api/v1/university/externals'),
        'auth_url' => env('UNIVERSITY_AUTH_URL', 'https://sso.unitepc.solutions/auth/token'),
        'client_id' => env('UNIVERSITY_CLIENT_ID'),
        'client_secret' => env('UNIVERSITY_CLIENT_SECRET'),
    ],

    'grupos_api' => [
        'url' => env('GRUPOS_API_URL', 'http://181.188.185.211:9098'),
    ],

];
