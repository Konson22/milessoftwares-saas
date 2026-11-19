<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Logbook\Access;
use App\Logbook\Entries;
use App\Logbook\Models\Entry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reviewing from the phone: online only, since a decision must be made on
 * the entry as the server has it now.
 */
class ReviewController extends Controller
{
    public function index(): JsonResponse
    {
        $entries = Access::reviewable()
            ->with(['sheet:id,name,code', 'logbook:id,name', 'version:id,fields', 'creator:id,name'])
            ->orderBy('submitted_at')
            ->limit(100)
            ->get();

        return response()->json([
            'entries' => $entries->map(fn (Entry $entry) => [...self::entryRow($entry), 'revision' => $entry->revision])->values()->all(),
        ]);
    }

    /**
     * Marks reviewed or returns, for the revision the reviewer read.
     */
    public function decide(Request $request, string $entry): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:reviewed,returned'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        $entry = Access::entry($entry);

        if ($entry->revision !== $data['revision'] || $entry->status !== 'submitted') {
            return response()->json([
                'code' => 'stale',
                'message' => 'This entry was changed or decided since you opened it.',
                'status' => $entry->status,
            ], 409);
        }

        $entry = Entries::review($entry, $this->user(), $data['decision'], $data['comment'] ?? null);

        return response()->json(['id' => $entry->id, 'status' => $entry->status, 'revision' => $entry->revision]);
    }
}
