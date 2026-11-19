<?php

namespace Miles\PlatformClient\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * /<path>/up: the product is up, under the path nginx sends it (Laravel's
 * own /up is never reached there). Boots the app, reaches its database and
 * lets listeners of DiagnosingHealth object. Any host, no session; the
 * deploy checks it before keeping a release.
 */
final class HealthController
{
    public function __invoke(): Response
    {
        try {
            DB::connection()->select('select 1');
            Event::dispatch(new DiagnosingHealth);
        } catch (Throwable $e) {
            report($e);

            return response('Unavailable', 503, ['Cache-Control' => 'no-store']);
        }

        return response('OK', 200, ['Cache-Control' => 'no-store']);
    }
}
