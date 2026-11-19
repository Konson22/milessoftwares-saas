<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Entries;
use App\Logbook\Models\Entry;
use App\Logbook\Models\EntryReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    /** Entries waiting on this member's review, and what they decided lately. */
    public function index(): Response
    {
        $me = $this->user()->id;

        return Inertia::render('reviews/index', [
            'pending' => Access::reviewable()->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'version:id,fields'])
                ->orderBy('submitted_at')->limit(200)->get()
                ->map(fn (Entry $e) => [...self::entryRow($e), 'revision' => $e->revision]),
            'decided' => EntryReview::query()->where('reviewer_id', $me)->latest('decided_at')->limit(20)->get()
                ->map(function (EntryReview $r) {
                    $entry = Access::entries()->with('sheet:id,name,code')->find($r->log_entry_id);

                    return $entry === null ? null : [
                        'id' => $r->id, 'entryId' => $entry->id, 'reference' => $entry->reference, 'sheet' => $entry->sheet->name,
                        'decision' => $r->decision, 'revision' => $r->revision, 'decidedAt' => self::time($r->decided_at),
                    ];
                })->filter()->values(),
        ]);
    }

    public function decide(Request $request, string $entry): RedirectResponse
    {
        $entry = Access::entry($entry);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['reviewed', 'returned'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $entry = Entries::review($entry, $this->user(), $data['decision'], $data['comment'] ?? null);

        return to_route('reviews.index')->with('success', $entry->status === 'reviewed'
            ? "{$entry->reference} marked reviewed."
            : "{$entry->reference} returned to its author for correction.");
    }
}
