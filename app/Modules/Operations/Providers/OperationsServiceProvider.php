<?php

namespace App\Modules\Operations\Providers;

use App\Modules\Operations\Console\Preflight;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Preflight::class]);
        }

        // GET /up answers 500 when a dependency every request needs is down,
        // so load balancers take the node out and uptime checks page on-call.
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('SELECT 1');
            Cache::store()->get('platform:health');
        });

        // Log lines at error/critical are what alerting watches
        // (docs/runbooks/monitoring.md).
        Event::listen(QueueBusy::class, function (QueueBusy $event): void {
            Log::critical('Queue backlog over threshold', [
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            Log::error('Queued job failed', [
                'job' => $event->job->resolveName(),
                'uuid' => $event->job->uuid(),
                'connection' => $event->connectionName,
                'exception' => $event->exception->getMessage(),
            ]);
        });
    }
}
