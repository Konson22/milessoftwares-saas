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

    // Sign in with Google (auth host). Google only accepts http redirect URIs
    // on plain localhost, so local development registers
    // http://localhost/auth/google/callback and Apache forwards it to the
    // auth host (ops/local-dev/apache-dev.conf). Production uses
    // https://auth.<root domain>/auth/google/callback.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        // Native sign-in in the mobile apps: ID tokens are accepted for the
        // client ID above (the apps' webClientId) and these, comma-separated
        // (iOS client IDs, should a token be issued for one).
        'native_client_ids' => env('GOOGLE_NATIVE_CLIENT_IDS'),
        // Public site only (resources/views/app.blade.php). Empty: off.
        // Search Console's HTML-tag token (the content="..." value); not
        // needed when the domain is verified by DNS TXT record.
        'site_verification' => env('GOOGLE_SITE_VERIFICATION'),
        // Google Analytics 4 measurement ID, G-XXXXXXXXXX. Ignored while a
        // Tag Manager container is set: GA4 is then a tag in the container.
        'analytics_id' => env('GOOGLE_ANALYTICS_ID'),
        // Google Tag Manager container ID, GTM-XXXXXXX.
        'tag_manager_id' => env('GOOGLE_TAG_MANAGER_ID'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
