<?php

namespace App\Modules\Audit\Console;

use App\Modules\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled daily. A broken chain means an audit row was altered or removed
 * outside the application and is logged as critical for on-call.
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'platform:audit-verify';

    protected $description = 'Verify the audit log hash chain end to end';

    public function handle(AuditLogger $audit): int
    {
        $broken = $audit->verifyChain();

        if ($broken !== null) {
            Log::critical('Audit hash chain broken', ['sequence' => $broken]);
            $this->components->error("Audit chain broken at sequence {$broken}.");

            return self::FAILURE;
        }

        $this->components->info('Audit chain intact.');

        return self::SUCCESS;
    }
}
