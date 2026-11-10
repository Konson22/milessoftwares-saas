<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\AuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        return Inertia::render('audit/index', [
            'logs' => AuditLog::query()->with('actor')->where('branch_id', $this->branchId())->latest('created_at')->limit(100)->get()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'subject' => class_basename((string) $log->subject_type),
                'actor' => $log->actor?->name,
                'at' => $log->created_at->toIso8601String(),
            ]),
        ]);
    }
}
