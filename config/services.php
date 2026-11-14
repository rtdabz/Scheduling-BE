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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/api/auth/google/callback'),
        'allowed_domain' => env('GOOGLE_ALLOWED_DOMAIN'),
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
     * Approximate IP geolocation for the Activity Log "Login Locations" view.
     * Only the client IP is sent to the provider; coordinates are rounded to ~1 km
     * and location rows are pruned after the retention window (audit rows are kept).
     */
    'ip_geolocation' => [
        'enabled' => (bool) env('IP_GEOLOCATION_ENABLED', true),
        'provider' => env('IP_GEOLOCATION_PROVIDER', 'ipapi'), // ipapi (ipapi.co, HTTPS) | ip-api (ip-api.com, flags VPN/hosting)
        'timeout' => (int) env('IP_GEOLOCATION_TIMEOUT', 3),
        'retention_days' => (int) env('IP_GEOLOCATION_RETENTION_DAYS', 90),
    ],

];
