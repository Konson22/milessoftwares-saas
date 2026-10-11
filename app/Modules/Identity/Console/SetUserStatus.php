<?php

namespace App\Modules\Identity\Console;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Support\SessionTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Incident response for a compromised or departing account
 * (docs/runbooks/incidents.md). Anything but active also signs the user out
 * everywhere and revokes every token issued to products for them, including
 * refresh tokens, so no product can keep acting on their behalf.
 */
class SetUserStatus extends Command
{
    protected $signature = 'platform:user-status
        {email : The account}
        {status : active, suspended or deactivated}
        {--reason= : Why (required, stored in the audit log)}';

    protected $description = 'Suspend, deactivate or reactivate a user account';

    private const STATUSES = ['active', 'suspended', 'deactivated'];

    public function handle(SessionTracker $sessions, AuditLogger $audit): int
    {
        $status = (string) $this->argument('status');
        $reason = trim((string) $this->option('reason'));

        if (! in_array($status, self::STATUSES, true)) {
            $this->components->error('Status must be one of: '.implode(', ', self::STATUSES).'.');

            return self::INVALID;
        }

        if ($reason === '') {
            $this->components->error('A --reason is required.');

            return self::INVALID;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $this->argument('email'))])->first();
        if ($user === null) {
            $this->components->error('No such account.');

            return self::FAILURE;
        }

        $previous = $user->status;

        [$revokedSessions, $revokedTokens] = DB::transaction(function () use ($user, $status, $reason, $previous, $sessions, $audit): array {
            $user->forceFill(['status' => $status])->save();

            $revokedSessions = 0;
            $revokedTokens = 0;

            if ($status !== 'active') {
                $revokedSessions = $sessions->revokeAll($user, 'account_'.$status);

                $tokenIds = DB::table('oauth_access_tokens')
                    ->where('user_id', $user->getKey())
                    ->where('revoked', false)
                    ->pluck('id');

                $revokedTokens = DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->update(['revoked' => true]);
                DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
            }

            $audit->record('user.status_changed', $user, [
                'from' => $previous,
                'to' => $status,
                'reason' => $reason,
                'via' => 'console',
            ], organizationId: null);

            return [$revokedSessions, $revokedTokens];
        });

        $this->components->info("{$user->email}: {$previous} → {$status}; {$revokedSessions} session(s) and {$revokedTokens} token(s) revoked.");

        return self::SUCCESS;
    }
}
