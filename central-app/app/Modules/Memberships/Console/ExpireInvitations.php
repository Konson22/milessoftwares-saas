<?php

namespace App\Modules\Memberships\Console;

use App\Modules\Memberships\Models\Invitation;
use Illuminate\Console\Command;

/**
 * Marks pending invitations past their expiry as expired, so lists and the
 * one-pending-invitation-per-email rule reflect reality. Acceptance checks
 * the expiry timestamp itself and never relies on this job.
 */
class ExpireInvitations extends Command
{
    protected $signature = 'platform:expire-invitations';

    protected $description = 'Mark pending invitations past their expiry as expired';

    public function handle(): int
    {
        $count = Invitation::acrossTenants()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $this->components->info("{$count} invitations expired.");

        return self::SUCCESS;
    }
}
