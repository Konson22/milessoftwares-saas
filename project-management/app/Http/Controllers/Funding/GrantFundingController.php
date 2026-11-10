<?php

namespace App\Http\Controllers\Funding;

use App\Actions\DonorPortal;
use App\Actions\Grants;
use App\Audit\Audit;
use App\Enums\GrantReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Models\GrantAllocation;
use App\Models\GrantDisbursement;
use App\Models\GrantReport;
use App\Models\Project;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What an awarded grant pays for and when: allocations to projects, the
 * payment schedule, and the reports owed to the funder. Nested records
 * resolve through their grant (scoped bindings).
 */
class GrantFundingController extends Controller
{
    public function allocate(Request $request, Grant $grant): RedirectResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'uuid', Rule::exists('projects', 'id')->where('organization_id', app(CurrentOrganization::class)->id())],
            'amount' => ['required', 'string', Money::rule($grant->currency)],
            'exchange_rate' => ['nullable', 'string', 'regex:'.Money::RATE_PATTERN],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['exchange_rate.regex' => 'A rate like 2450.5 (up to 8 decimals).']);

        $project = Project::query()->whereKey($validated['project_id'])->firstOrFail();
        Grants::allocate($grant, $project, Money::toMinor($validated['amount'], $grant->currency), $validated['exchange_rate'] ?? null, $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', "Allocated to {$project->code}.");
    }

    public function updateAllocation(Request $request, Grant $grant, GrantAllocation $allocation): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', Money::rule($grant->currency)],
            'exchange_rate' => ['nullable', 'string', 'regex:'.Money::RATE_PATTERN],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        Grants::allocate($grant, $allocation->project, Money::toMinor($validated['amount'], $grant->currency), $validated['exchange_rate'] ?? null, $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', 'Allocation saved.');
    }

    public function removeAllocation(Request $request, Grant $grant, GrantAllocation $allocation): RedirectResponse
    {
        Grants::removeAllocation($allocation, $this->member($request));

        return back()->with('success', 'Allocation removed.');
    }

    public function schedule(Request $request, Grant $grant): RedirectResponse
    {
        $validated = $request->validate([
            'due_on' => ['required', 'date'],
            'amount' => ['required', 'string', Money::rule($grant->currency)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        Grants::schedule($grant, CarbonImmutable::parse($validated['due_on']), Money::toMinor($validated['amount'], $grant->currency), $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', 'Payment scheduled.');
    }

    public function receive(Request $request, Grant $grant, GrantDisbursement $disbursement): RedirectResponse
    {
        $on = $request->validate(['on' => ['required', 'date']])['on'];
        Grants::receive($disbursement, CarbonImmutable::parse($on), $this->member($request));

        return back()->with('success', 'Payment recorded as received.');
    }

    public function unschedule(Request $request, Grant $grant, GrantDisbursement $disbursement): RedirectResponse
    {
        Grants::unschedule($disbursement, $this->member($request));

        return back()->with('success', 'Payment taken off the schedule.');
    }

    public function addReport(Request $request, Grant $grant): RedirectResponse
    {
        if (! in_array($grant->status->value, ['applied', 'active'], true)) {
            throw ValidationException::withMessages(['grant' => 'Reports are tracked on an application or an active grant.']);
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(array_keys(GrantReport::KINDS))],
            'due_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $report = $grant->reports()->create([
            'title' => trim($validated['title']),
            'kind' => $validated['kind'],
            'due_on' => $validated['due_on'],
            'status' => GrantReportStatus::Due,
            'notes' => $this->text($validated['notes'] ?? null),
        ]);
        Audit::record('grant.report_required', $report, ['grant' => $grant->reference, 'title' => $report->title, 'due_on' => $report->due_on->toDateString()]);

        return back()->with('success', 'Reporting requirement added.');
    }

    public function moveReport(Request $request, Grant $grant, GrantReport $report): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(GrantReportStatus::class)],
            'on' => ['required', 'date', 'before_or_equal:today'],
        ]);
        Grants::moveReport($report, GrantReportStatus::from($validated['status']), CarbonImmutable::parse($validated['on']), $this->member($request));

        return back()->with('success', "{$report->title}: {$report->status->label()}.");
    }

    /**
     * Only a requirement never submitted can be removed (one added by
     * mistake); anything submitted stays on record.
     */
    public function removeReport(Request $request, Grant $grant, GrantReport $report): RedirectResponse
    {
        if ($report->status !== GrantReportStatus::Due || $report->submitted_on !== null) {
            throw ValidationException::withMessages(['report' => 'A report that was submitted stays on record.']);
        }

        foreach ($report->documents as $document) {
            DonorPortal::detach($report, $document, $this->member($request));
        }
        $report->delete();
        Audit::record('grant.report_removed', $report, ['grant' => $grant->reference, 'title' => $report->title]);

        return back()->with('success', 'Reporting requirement removed.');
    }
}
