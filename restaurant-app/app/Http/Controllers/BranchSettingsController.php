<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\BranchSetting;
use App\Support\Branches;
use App\Support\CurrentBranch;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * How the branch being worked in runs the restaurant: its receipt lines,
 * the usual starting cash and when a kitchen ticket is late. Its name,
 * address and whether it is open are the workspace's (Structure), shared
 * by every app, so they are shown here and changed there.
 */
class BranchSettingsController extends Controller
{
    use WorksInBranch;

    public function edit(Request $request, CurrentBranch $current, Branches $branches, CurrentAccess $access): Response
    {
        $branch = $current->require();
        $settings = BranchSetting::for($branch->id);
        $isOwner = $access->get()->isOwner ?? false;

        return Inertia::render('settings/branch', [
            'details' => [
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
            ],
            'settings' => [
                'receipt_header' => $settings->receipt_header,
                'receipt_footer' => $settings->receipt_footer,
                'phone' => $settings->phone,
                'default_float' => $settings->default_float_minor === null ? null : Money::toMajor($settings->default_float_minor),
                'slow_ticket_minutes' => $settings->slow_ticket_minutes,
            ],
            'defaultSlowMinutes' => BranchSetting::SLOW_TICKET_MINUTES,
            'structureUrl' => $isOwner || $branches->managesAccess() ? $request->getSchemeAndHttpHost().'/manage/structure' : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'receipt_header' => ['nullable', 'string', 'max:255'],
            'receipt_footer' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'default_float' => ['nullable', 'string', Money::rule()],
            'slow_ticket_minutes' => ['nullable', 'integer', 'min:'.BranchSetting::SLOW_TICKET_MINUTES_MIN, 'max:240'],
        ]);
        $text = fn (string $key) => blank($validated[$key] ?? null) ? null : trim((string) $validated[$key]);

        $settings = BranchSetting::for($this->branchId());
        $settings->fill([
            'receipt_header' => $text('receipt_header'),
            'receipt_footer' => $text('receipt_footer'),
            'phone' => $text('phone'),
            'default_float_minor' => blank($validated['default_float'] ?? null) ? null : Money::toMinor($validated['default_float']),
            'slow_ticket_minutes' => blank($validated['slow_ticket_minutes'] ?? null) ? null : (int) $validated['slow_ticket_minutes'],
            'updated_by' => $this->member($request)->id,
        ])->save();

        return to_route('branch.settings.edit')->with('success', 'Branch settings saved.');
    }
}
