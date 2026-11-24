<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Logbook\Access;
use App\Logbook\Attachments;
use App\Logbook\Entries;
use App\Logbook\Models\Attachment;
use App\Logbook\Models\Entry;
use App\Logbook\Models\EntryReview;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Stops;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The phone app's entries. An entry written offline reaches the server in
 * steps, each safe to repeat:
 *
 * 1. POST entries: created as a draft, keyed by the phone's id;
 * 2. POST entries/{id}/attachments: each file, deduplicated by hash;
 * 3. POST entries/{id}/submit with the revision it creates.
 *
 * A returned entry is corrected with PUT entries/{id} (from the revision
 * the phone saw), its files, then submit. Conflicts answer 409 with a code
 * the phone shows as "action required"; they are never retried by it.
 */
class EntryController extends Controller
{
    /**
     * The member's own entries changed since a moment (or the last 90
     * days): how the phone learns of references, reviews and returns.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['nullable', 'date']]);
        $since = isset($data['since']) ? CarbonImmutable::parse($data['since']) : now()->subDays(90);
        $now = now();

        $entries = Entry::query()
            ->where('created_by', $this->user()->id)
            ->where('updated_at', '>=', $since)
            ->with(['sheet:id,name,code', 'logbook:id,name', 'version:id,version,fields'])
            ->orderBy('updated_at')
            ->limit(500)
            ->get();

        return response()->json([
            'serverTime' => $now->toIso8601String(),
            'complete' => $entries->count() < 500,
            'entries' => $entries->map(fn (Entry $entry) => $this->entry($entry))->values()->all(),
        ]);
    }

    public function show(string $entry): JsonResponse
    {
        $entry = Access::entry($entry);
        $entry->load(['sheet:id,name,code', 'logbook:id,name', 'version:id,version,fields', 'creator:id,name']);
        $files = $entry->attachments()->get();

        return response()->json([
            ...$this->entry($entry),
            'creator' => $entry->creator->name,
            'answers' => self::answers($entry->version->fields, $entry->values, $files),
            'reviews' => $entry->reviews()->orderBy('decided_at')->get()->map(fn (EntryReview $r) => [
                'revision' => $r->revision,
                'decision' => $r->decision,
                'comment' => $r->comment,
                'reviewer' => $r->reviewer_name,
                'decidedAt' => self::time($r->decided_at),
            ])->values()->all(),
            // ADR-0030: what the web entry page shows, read online on the phone.
            'shift' => $entry->shift?->label(),
            'activityEntry' => ($parent = $entry->activity_entry_id !== null ? Access::entries()->with('sheet:id,name')->find($entry->activity_entry_id) : null) === null
                ? null : ['id' => $parent->id, 'label' => ($parent->reference ?? 'Draft').' · '.$parent->sheet->name],
            'calculation' => self::calculationRow($entry->calculation),
            'canReview' => $entry->status === 'submitted' && $entry->created_by !== $this->user()->id
                && Access::reachesSheet($entry->sheet, 'review'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'sheet_id' => ['required', 'uuid'],
            'version_id' => ['required', 'uuid'],
            'occurred_at' => ['required', 'date'],
            'values' => ['nullable', 'array'],
            'submit' => ['boolean'],
            'occurrence_id' => ['nullable', 'uuid'],
            // ADR-0032: the planned trip it records.
            'dispatch_id' => ['nullable', 'uuid'],
            // ADR-0030: newer phone builds say named or extra; older ones say nothing (matched by time).
            'schedule_link' => ['nullable', Rule::in(['named', 'extra'])],
            'shift_id' => ['nullable', 'uuid'],
            'activity_started_at' => ['nullable', 'date'],
            'activity_ended_at' => ['nullable', 'date'],
            'activity_entry_id' => ['nullable', 'uuid'],
            // ADR-0032 §5: the trip's stops, times with their offset.
            'stops' => ['nullable', 'array'],
            'calculation' => ['nullable', 'array'],
            'calculation.estimated_l' => ['nullable', 'numeric'],
            'calculation.rate_id' => ['nullable', 'uuid'],
            'calculation.rate' => ['nullable', 'numeric'],
            'calculation.quantity' => ['nullable', 'numeric'],
            'location' => ['nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'location.captured_at' => ['required_with:location', 'date'],
            'track_id' => ['nullable', 'uuid'],
        ]);
        $user = $this->user();

        // A repeat after a lost reply: the entry the first request made.
        if (($existing = Entries::byKey($user, $data['id'])) !== null) {
            return response()->json($this->entry($existing->load(['sheet', 'logbook', 'version'])));
        }

        $sheet = Access::writableSheets()->whereKey($data['sheet_id'])->first();
        if ($sheet === null) {
            return self::refused('sheet_closed', 'This log sheet no longer takes entries from you.', 409);
        }
        $version = LogSheetVersion::query()->whereKey($data['version_id'])->first();
        if ($version === null) {
            return self::refused('version_unknown', 'The template version this entry was written on is unknown.', 409);
        }

        $entry = Entries::create($sheet, $user, $data['values'] ?? [], $data['occurred_at'], $data['id'], (bool) ($data['submit'] ?? false), $version, $data['occurrence_id'] ?? null, ['location' => $data['location'] ?? null, 'track_id' => $data['track_id'] ?? null, 'source' => 'phone'], $data['schedule_link'] ?? null, ['shift_id' => $data['shift_id'] ?? null, 'dispatch_id' => $data['dispatch_id'] ?? null, ...self::activityMore($request, $data)]);

        return response()->json($this->entry($entry->load(['sheet', 'logbook', 'version'])), 201);
    }

    /**
     * The activity fields the phone sent (ADR-0030), with its own estimate
     * kept as evidence only.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function activityMore(Request $request, array $data): array
    {
        $out = [];
        foreach (['activity_started_at', 'activity_ended_at', 'activity_entry_id'] as $key) {
            if ($request->has($key)) {
                $out[$key] = $data[$key] ?? null;
            }
        }
        if (is_array($data['calculation'] ?? null)) {
            $out['phone_calculation'] = $data['calculation'];
        }
        if ($request->has('stops')) {
            $out['stops'] = Stops::input($request->input('stops'), localTimes: false);
        }

        return $out;
    }

    /**
     * Saves a correction (or a draft) made from revision `base_revision`.
     */
    public function update(Request $request, string $entry): JsonResponse
    {
        $data = $request->validate([
            'base_revision' => ['required', 'integer', 'min:0'],
            'occurred_at' => ['required', 'date'],
            'values' => ['nullable', 'array'],
            'location' => ['nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'location.captured_at' => ['required_with:location', 'date'],
            'track_id' => ['nullable', 'uuid'],
            'shift_id' => ['nullable', 'uuid'],
            'activity_started_at' => ['nullable', 'date'],
            'activity_ended_at' => ['nullable', 'date'],
            'activity_entry_id' => ['nullable', 'uuid'],
            // ADR-0032 §5: the trip's stops, times with their offset.
            'stops' => ['nullable', 'array'],
            'calculation' => ['nullable', 'array'],
            'calculation.estimated_l' => ['nullable', 'numeric'],
            'calculation.rate_id' => ['nullable', 'uuid'],
            'calculation.rate' => ['nullable', 'numeric'],
            'calculation.quantity' => ['nullable', 'numeric'],
        ]);
        $entry = $this->own($entry);

        // Already saved and submitted by an earlier attempt.
        if ($entry->revision === $data['base_revision'] + 1 && ! $entry->editable()) {
            return response()->json($this->entry($entry));
        }
        if ($entry->revision !== $data['base_revision']) {
            return self::refused('stale', 'This entry changed since the phone last saw it.', 409);
        }
        if (! $entry->editable()) {
            return self::refused('not_editable', 'This entry is '.$entry->status.' and can no longer be changed.', 409);
        }

        $place = $request->has('location') || $request->has('track_id') ? ['location' => $data['location'] ?? null, 'track_id' => $data['track_id'] ?? null, 'source' => 'phone'] : null;
        $entry = Entries::update($entry, $this->user(), $data['values'] ?? [], $data['occurred_at'], $place, [...($request->has('shift_id') ? ['shift_id' => $data['shift_id'] ?? null] : []), ...self::activityMore($request, $data)]);

        return response()->json($this->entry($entry->load(['sheet', 'logbook', 'version'])));
    }

    /**
     * Submits, creating revision `revision`. Repeating it after it went
     * through answers the entry as it is.
     */
    public function submit(Request $request, string $entry): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $entry = $this->own($entry);

        if ($entry->revision >= $data['revision']) {
            return response()->json($this->entry($entry));
        }
        if ($entry->revision !== $data['revision'] - 1) {
            return self::refused('stale', 'This entry changed since the phone last saw it.', 409);
        }
        if (! $entry->editable()) {
            return self::refused('not_editable', 'This entry is '.$entry->status.' and can no longer be changed.', 409);
        }

        $entry = Entries::submit($entry, $this->user());

        return response()->json($this->entry($entry->load(['sheet', 'logbook', 'version'])));
    }

    /**
     * Discards a draft never submitted. Gone already: nothing to do.
     */
    public function destroy(string $entry): Response
    {
        $entry = Entry::query()->where('created_by', $this->user()->id)->whereKey($entry)->first();
        if ($entry !== null) {
            Entries::discard($entry, $this->user());
        }

        return response()->noContent();
    }

    public function attach(Request $request, string $entry): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string', 'max:64'],
            'file' => ['required', 'file', 'max:'.Attachments::MAX_KILOBYTES, 'mimes:'.Attachments::MIMES],
        ]);
        $entry = $this->own($entry);
        $attachment = Attachments::add($entry, $data['field'], $data['file'], $this->user(), dedupe: true);

        return response()->json(self::file($attachment), $attachment->wasRecentlyCreated ? 201 : 200);
    }

    public function detach(string $attachment): Response
    {
        $attachment = Attachment::query()->whereKey($attachment)->first();
        if ($attachment !== null && $attachment->removed_at === null) {
            $this->own($attachment->log_entry_id);
            Attachments::remove($attachment, $this->user());
        }

        return response()->noContent();
    }

    public function download(string $attachment): StreamedResponse
    {
        $attachment = Attachment::query()->findOrFail($attachment);
        Access::entry($attachment->log_entry_id);
        abort_unless(Storage::disk(Attachments::DISK)->exists($attachment->path), 404);

        return Storage::disk(Attachments::DISK)->download($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The member's own entry, or 404. */
    private function own(string $id): Entry
    {
        return Entry::query()->where('created_by', $this->user()->id)->whereKey($id)
            ->with(['sheet', 'logbook', 'version'])->firstOrFail();
    }

    /**
     * An entry as the phone keeps it.
     *
     * @return array<string, mixed>
     */
    private function entry(Entry $entry): array
    {
        $latest = $entry->reviews()->latest('decided_at')->first();

        return [
            'id' => $entry->id,
            'key' => $entry->idempotency_key,
            'reference' => $entry->reference,
            'status' => $entry->status,
            'revision' => $entry->revision,
            'reviewRequired' => $entry->review_required,
            'sheet' => ['id' => $entry->log_sheet_id, 'name' => $entry->sheet->name, 'code' => $entry->sheet->code],
            'logbook' => $entry->logbook->name,
            'versionId' => $entry->log_sheet_version_id,
            'occurredAt' => self::time($entry->occurred_at),
            'submittedAt' => self::time($entry->submitted_at),
            'reviewedAt' => self::time($entry->reviewed_at),
            'updatedAt' => self::time($entry->updated_at),
            'values' => (object) $entry->values,
            'location' => $entry->location,
            'trackId' => $entry->log_track_id,
            // ADR-0030: so a phone correcting an entry keeps its shift and activity.
            'shiftId' => $entry->shift_id,
            // ADR-0031: the asset the entry is about.
            'resourceId' => $entry->resource_id,
            'activityStartedAt' => self::time($entry->activity_started_at),
            'activityEndedAt' => self::time($entry->activity_ended_at),
            'stops' => $entry->stops ?? [],
            'files' => $entry->attachments()->whereNull('removed_at')->get()->map(fn (Attachment $a) => self::file($a))->values()->all(),
            'lastReview' => $latest instanceof EntryReview ? [
                'revision' => $latest->revision,
                'decision' => $latest->decision,
                'comment' => $latest->comment,
                'reviewer' => $latest->reviewer_name,
                'decidedAt' => self::time($latest->decided_at),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function file(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'field' => $attachment->field,
            'name' => $attachment->name,
            'size' => $attachment->size,
            'mime' => $attachment->mime,
            'sha256' => $attachment->sha256,
        ];
    }

    private static function refused(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
