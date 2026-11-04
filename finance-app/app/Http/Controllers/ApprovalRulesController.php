<?php

namespace App\Http\Controllers;

use App\Finance\Money;
use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;

/**
 * When journals and payments need approval, in one place of its own: whoever
 * sets the rules need not be able to see what waits under them.
 */
class ApprovalRulesController extends Controller
{
    public function __invoke(): Response
    {
        $workspace = Workspace::current();
        $major = fn (?int $minor) => $minor === null ? '' : Money::toMajor($minor);

        return Inertia::render('approval-rules/index', [
            'journals' => [
                'enabled' => $workspace->journal_approval ?? false,
                'threshold' => $major($workspace?->journal_approval_threshold_minor),
            ],
            'payments' => [
                'approval' => $workspace->payment_approval ?? false,
                'threshold' => $major($workspace?->payment_approval_threshold_minor),
                'review' => $workspace->payment_review ?? false,
                'separation' => $workspace->payment_separation ?? true,
            ],
        ]);
    }
}
