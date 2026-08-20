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

    'zoom' => [
        'sdk_key' => env('ZOOM_SDK_KEY'),
        'sdk_secret' => env('ZOOM_SDK_SECRET'),
        // Meetings API OAuth (S14 G7) — a separate credential pair from the
        // sdk_key/sdk_secret above: those sign a client-side Video SDK JWT
        // against one platform-wide app, these are the OAuth app used to
        // connect each institute's own Zoom account (design doc decision 1).
        'oauth_client_id' => env('ZOOM_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('ZOOM_OAUTH_CLIENT_SECRET'),
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        // Subscriptions API (S11) uses the same key_id/key_secret as the
        // Orders API above — one Razorpay account — but a separate webhook
        // endpoint (/webhooks/razorpay-subscriptions, S11 B7) with its own
        // secret, same as Razorpay lets any account configure more than
        // one webhook URL.
        'subscription_webhook_secret' => env('RAZORPAY_SUBSCRIPTION_WEBHOOK_SECRET'),
    ],

    'aisensy' => [
        'api_key' => env('AISENSY_API_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
