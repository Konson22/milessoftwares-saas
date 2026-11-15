<?php

namespace App\FinanceIntegration;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\FinanceRequest;
use App\Models\Organization;
use App\Models\PettyCashFund;
use App\Models\Project;
use App\Models\StaffAdvance;
use App\Support\FinanceEvents;
use App\Support\FinanceSync;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;

/**
 * Whether an organization may switch Finance mode: read-only checks, each
 * with a count and whether it blocks the switch asked for. Nothing here
 * changes a record; clearing what blocks is done in the usual places
 * (pay or release commitments, settle advances, move petty cash to
 * Finance, deliver or settle outbox events).
 */
final class Readiness
{
    public function __construct(private readonly PlatformApi $platform, private readonly CurrentOrganization $current) {}

    /**
     * @return list<array{key: string, label: string, count: int, passed: bool, blocking: bool, detail: string}>
     */
    public function check(Organization $organization, FinanceMode $to): array
    {
        $from = FinanceMode::of($organization);

        return $this->current->run($organization, function () use ($organization, $from, $to) {
            $handover = $to === FinanceMode::Ready || ($to === FinanceMode::Integrated && $from === FinanceMode::Ready);
            $resume = $to === FinanceMode::Integrated && $from === FinanceMode::Suspended;
            $leaving = $to === FinanceMode::Legacy && $from === FinanceMode::Suspended;
            $checks = [];

            $enabled = (bool) config('finance_integration.enabled');
            $checks[] = self::item('integration_enabled', 'Finance integration is switched on for this deployment', $enabled ? 0 : 1, $to === FinanceMode::Integrated, $enabled ? 'PROJECTS_FINANCE_INTEGRATION is on.' : 'PROJECTS_FINANCE_INTEGRATION is off: Finance does not take spending requests yet.');

            [$connected, $why] = $this->financeConnected($organization);
            $checks[] = self::item('finance_connected', 'The organization has Finance', $connected ? 0 : 1, $handover || $resume, $why);

            $legacyCommitments = Expense::query()->where('status', ExpenseStatus::Approved)
                ->whereNotIn('id', FinanceRequest::query()->select('expense_id'))->count();
            $checks[] = self::item('legacy_commitments', 'Approved spending not yet paid, recorded in Projects', $legacyCommitments, $handover, 'Pay or release it here first: Finance would not know it.');

            $pending = Expense::query()->where('status', ExpenseStatus::Requested)->count();
            $checks[] = self::item('pending_approvals', 'Spending awaiting approval', $pending, false, 'Once approved in integrated mode it goes to Finance as a request.');

            $advances = StaffAdvance::query()->whereIn('status', StaffAdvance::OPEN)->count();
            $checks[] = self::item('open_advances', 'Staff advances requested, approved or not yet accounted for', $advances, $handover, 'Settle them here first: advances stay in Projects in this phase.');

            $boxes = PettyCashFund::query()->where('status', PettyCashFund::ACTIVE)->count();
            $checks[] = self::item('petty_cash', 'Petty cash boxes still kept in Projects', $boxes, $handover, 'Move them into Finance funds first.');

            $unsettled = DB::table('platform_outbox')->where('organization_id', $organization->id)->where('audience', FinanceEvents::AUDIENCE)->whereIn('status', ['pending', 'failed'])->count();
            $checks[] = self::item('unacknowledged_events', 'Money events Finance has not acknowledged', $unsettled, $handover, 'Every payment Projects recorded must have reached Finance (projects:finance-sync).');

            $exceptions = count(FinanceSync::exceptions($organization->id));
            $checks[] = self::item('sync_exceptions', 'Finance sync exceptions', $exceptions, $handover, 'Rejected, conflicting or missing payment events (projects:finance-sync).');

            $open = FinanceRequest::query()->get(['status'])->filter(fn (FinanceRequest $r) => $r->status->isOpen())->count();
            $checks[] = self::item('open_requests', 'Requests still open in Finance', $open, $leaving, 'Finance must settle or cancel them before Projects records payments again.');

            $rejections = DB::table('finance_inbox_rejections')->where('organization_id', $organization->id)->where('received_at', '>=', now()->subDays(30))->count();
            $checks[] = self::item('inbox_rejections', 'Finance outcomes refused in the last 30 days', $rejections, false, 'projects:finance-reconcile lists them.');

            $currencies = Project::query()->where('currency', '!=', $organization->currency)->count();
            $checks[] = self::item('other_currencies', "Projects not in {$organization->currency}", $currencies, false, 'Finance converts at its own rates; requests keep the project currency.');

            $history = Expense::query()->where('status', ExpenseStatus::Paid)->count();
            $checks[] = self::item('existing_records', 'Payments already recorded in Projects', $history, false, 'They stay in Projects as they are; nothing is moved.');

            return $checks;
        });
    }

    /**
     * @param  list<array{key: string, label: string, count: int, passed: bool, blocking: bool, detail: string}>  $checks
     * @return list<array{key: string, label: string, count: int, passed: bool, blocking: bool, detail: string}>
     */
    public static function blockers(array $checks): array
    {
        return array_values(array_filter($checks, fn (array $check) => $check['blocking'] && ! $check['passed']));
    }

    /**
     * @return array{bool, string}
     */
    private function financeConnected(Organization $organization): array
    {
        try {
            $this->platform->eventToken($organization->id, FinanceEvents::AUDIENCE);

            return [true, 'The platform issues Finance event tokens for it.'];
        } catch (NotVisible) {
            return [false, 'The organization does not have Finance.'];
        } catch (PlatformUnavailable) {
            return [false, 'The platform could not be asked: try again.'];
        }
    }

    /**
     * @return array{key: string, label: string, count: int, passed: bool, blocking: bool, detail: string}
     */
    private static function item(string $key, string $label, int $count, bool $required, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'count' => $count, 'passed' => $count === 0, 'blocking' => $required, 'detail' => $detail];
    }
}
