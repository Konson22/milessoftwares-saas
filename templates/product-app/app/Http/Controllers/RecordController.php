<?php

namespace App\Http\Controllers;

use App\Models\Record;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Example: a tenant-owned list behind catalog permissions. The routes say
 * which permission each action needs (->can(...)); queries are scoped to
 * the workspace by BelongsToTenant.
 */
class RecordController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('records/index', [
            'records' => Record::query()
                ->with('creator:id,name')
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (Record $record) => [
                    'id' => $record->id,
                    'title' => $record->title,
                    'createdBy' => $record->creator?->name,
                    'createdAt' => $record->created_at?->toIso8601String(),
                ]),
            'canCreate' => $request->user()?->can('blueprint.records.manage') ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $record = new Record($validated);
        $record->created_by = (string) $request->user()?->getAuthIdentifier();
        $record->save();

        return to_route('records.index')->with('success', 'Record added.');
    }
}
