<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Petrol\ExpenseCycles;
use Illuminate\Console\Command;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;

class GenerateExpenses extends Command
{
    protected $signature = 'expenses:generate';

    protected $description = 'Record due recurring expenses once each, without marking them paid';

    public function handle(ExpenseCycles $cycles, CurrentTenant $tenant): int
    {
        $made = 0;
        foreach (Workspace::acrossTenants()->get(['organization_id', 'display_name', 'currency', 'timezone']) as $workspace) {
            $made += $tenant->run(Organization::fromArray([
                'id' => $workspace->organization_id,
                'slug' => (string) $workspace->organization_id,
                'display_name' => $workspace->display_name,
                'status' => 'active',
                'default_currency' => $workspace->currency,
                'timezone' => $workspace->timezone,
            ]), fn () => $cycles->generateDue());
        }

        $this->components->info($made === 0 ? 'Nothing is due.' : $made.' expense'.($made === 1 ? '' : 's').' recorded.');

        return self::SUCCESS;
    }
}
