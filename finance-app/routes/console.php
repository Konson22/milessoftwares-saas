<?php

use App\Finance\RecurringJournals;
use App\Models\RecurringJournal;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retry events for shared modules (Finance, HR, Payroll) that are due.
Schedule::command('platform:outbox')->everyMinute()->withoutOverlapping();

// Post recurring journals that are due, for every organization.
Artisan::command('finance:recurring', function (RecurringJournals $recurring, CurrentTenant $tenant) {
    $organizations = RecurringJournal::acrossTenants()->where('active', true)->whereNotNull('next_on')->distinct()->pluck('organization_id');

    foreach ($organizations as $organizationId) {
        $workspace = Workspace::acrossTenants()->where('organization_id', $organizationId)->first();
        $organization = new Organization((string) $organizationId, (string) $workspace?->slug, (string) $workspace?->display_name, 'active', null, $workspace?->currency, $workspace?->timezone);

        $result = $tenant->run($organization, fn () => $recurring->runDue(CarbonImmutable::today()));
        $this->info(($workspace->slug ?? $organizationId).": {$result['posted']} posted, {$result['failed']} stopped.");
    }
})->purpose('Post the recurring journals that are due');
Schedule::command('finance:recurring')->dailyAt('01:00')->withoutOverlapping();
