<?php

namespace App\FinanceIntegration;

use App\Audit\Audit;
use App\Models\FinanceModeChange;
use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Switches an organization's Finance mode: only along FinanceMode::next(),
 * with a reason, when no check blocks (Readiness), and recorded with the
 * checks as they were. Called by a person (projects:finance-mode), never by
 * the application itself.
 */
final class FinanceModes
{
    public function __construct(private readonly Readiness $readiness, private readonly CurrentOrganization $current) {}

    /**
     * @throws ModeChangeRefused
     */
    public function change(Organization $organization, FinanceMode $to, string $reason, string $by): FinanceModeChange
    {
        if (trim($reason) === '') {
            throw new ModeChangeRefused('Say why the mode changes.', []);
        }

        return DB::transaction(function () use ($organization, $to, $reason, $by) {
            /** @var Organization $organization */
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $from = FinanceMode::of($organization);
            if (! $from->allows($to)) {
                throw new ModeChangeRefused("{$from->value} cannot switch to {$to->value}.", []);
            }

            $checks = $this->readiness->check($organization, $to);
            $blockers = Readiness::blockers($checks);
            if ($blockers !== []) {
                throw new ModeChangeRefused('Not ready: '.implode('; ', array_map(fn (array $b) => "{$b['label']} ({$b['count']})", $blockers)), $checks);
            }

            $organization->forceFill(['finance_mode' => $to->value, 'finance_mode_changed_at' => now()])->save();

            return $this->current->run($organization, function () use ($organization, $from, $to, $reason, $by, $checks) {
                $change = FinanceModeChange::query()->create([
                    'from_mode' => $from->value,
                    'to_mode' => $to->value,
                    'reason' => $reason,
                    'checks' => $checks,
                    'changed_by' => mb_substr($by, 0, 191),
                ]);
                Audit::record('finance_mode.changed', $change, ['from' => $from->value, 'to' => $to->value, 'reason' => $reason, 'by' => $by], $organization->id);

                return $change;
            });
        });
    }
}
