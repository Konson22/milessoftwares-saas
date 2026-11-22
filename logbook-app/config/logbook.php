<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push notifications (ADR-0027 §2.3)
    |--------------------------------------------------------------------------
    |
    | Sent through the Expo push service, which hands them to Firebase Cloud
    | Messaging for Android. Off until the Firebase project exists and its
    | FCM credentials are uploaded to the EAS project; while off, phones
    | still register and nothing is queued for push.
    |
    | access_token: an Expo access token with "enhanced push security" on,
    | so nobody else can push to these phones with a leaked token.
    |
    */

    'push' => [
        'enabled' => (bool) env('LOGBOOK_PUSH_ENABLED', false),
        'endpoint' => env('LOGBOOK_PUSH_ENDPOINT', 'https://exp.host/--/api/v2/push'),
        'access_token' => env('LOGBOOK_EXPO_ACCESS_TOKEN'),
        // The Android notification channel the phone app creates.
        'android_channel' => 'work',
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery retries
    |--------------------------------------------------------------------------
    |
    | Minutes before each next attempt of a failed email or push; after the
    | last one the delivery is marked failed. The in-app notification is
    | never lost: it is written before any delivery is tried.
    |
    */

    'delivery' => [
        'backoff' => [1, 5, 15, 60],
        // A pending delivery the queue has not sent after this long is picked up by the clock.
        'sweep_after_minutes' => 2,
        // Push receipts are asked for this long after sending (Expo keeps them for a day).
        'receipts_after_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Catching up
    |--------------------------------------------------------------------------
    |
    | After the clock has been down, recurring tasks and expected entries of
    | up to this many past days are still made (never before a series or
    | schedule was set up or resumed).
    |
    */

    'catch_up_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Routes (GPS, ADR-0028)
    |--------------------------------------------------------------------------
    |
    | Routes ended longer ago than retention_days are removed with their
    | points (entries keep their own location). Null or 0: kept.
    |
    */

    'tracks' => [
        'retention_days' => env('LOGBOOK_TRACK_RETENTION_DAYS', 730),
    ],

    /*
    | Map tiles for the route viewer. OpenStreetMap's own tiles are fine for
    | light use with attribution (their tile policy); for heavy use point
    | this at a paid or self-hosted tile server. Only the map background is
    | fetched from it: route points are never sent there.
    */

    'map' => [
        'tiles' => env('LOGBOOK_MAP_TILES', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('LOGBOOK_MAP_ATTRIBUTION', '&copy; OpenStreetMap contributors'),
    ],

];
