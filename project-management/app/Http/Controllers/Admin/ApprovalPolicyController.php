<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\ApprovalPolicy;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Administration → Settings → Spending approvals: bands of "from this
 * amount, this many approvers". Requests already made keep the number
 * they were made with.
 */
class ApprovalPolicyController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['currency' => mb_strtoupper(trim($request->string('currency')->toString()))]);
        $currency = $request->string('currency')->toString();
        $validated = $request->validate([
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'min_amount' => ['required', 'string', Money::rule($currency)],
            'approvals_required' => ['required', 'integer', 'between:1,'.ApprovalPolicy::MAX_APPROVERS],
        ], [
            'min_amount.regex' => 'An amount such as 5000 or 5000.50.',
        ]);

        $minor = Money::toMinor($validated['min_amount'], $currency);
        $exists = ApprovalPolicy::query()->where('subject', ApprovalPolicy::EXPENSE)
            ->where('currency', $currency)->where('min_amount_minor', $minor)->exists();
        if ($exists) {
            throw ValidationException::withMessages(['min_amount' => 'There is already a band from that amount: remove it first.']);
        }

        $policy = new ApprovalPolicy([
            'subject' => ApprovalPolicy::EXPENSE,
            'currency' => $currency,
            'min_amount_minor' => $minor,
            'approvals_required' => (int) $validated['approvals_required'],
            'created_by' => $this->member($request)->id,
        ]);
        $policy->save();

        Audit::record('approval_policy.created', $policy, $policy->only(['currency', 'min_amount_minor', 'approvals_required']));

        return back()->with('success', 'Approval band added. It applies to requests made from now on.');
    }

    public function destroy(ApprovalPolicy $policy): RedirectResponse
    {
        app(CurrentOrganization::class)->require();
        $facts = $policy->only(['currency', 'min_amount_minor', 'approvals_required']);
        $policy->delete();

        Audit::record('approval_policy.removed', $policy, $facts);

        return back()->with('success', 'Approval band removed. Requests already made keep theirs.');
    }
}
