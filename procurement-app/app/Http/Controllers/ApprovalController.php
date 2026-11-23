<?php

namespace App\Http\Controllers;

use App\Procurement\Approvals;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything waiting for this member's decision, across plans,
 * requisitions, sourcing events, awards and orders.
 */
class ApprovalController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('approvals/index', [
            'items' => Approvals::awaiting($this->user()),
        ]);
    }
}
