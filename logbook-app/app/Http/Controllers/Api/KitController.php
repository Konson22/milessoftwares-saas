<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Logbook\Access;
use App\Logbook\Attachments;
use App\Logbook\Fields;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Models\Shift;
use App\Logbook\Recording;
use App\Logbook\Tracks;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * What the phone app downloads to work offline: who is signed in, the
 * sheets they write on with their published version, and the versions'
 * fields (immutable, so fetched once by id).
 */
class KitController extends Controller
{
    public function me(CurrentTenant $tenant): JsonResponse
    {
        $user = $this->user();
        $organization = $tenant->require();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'organization' => ['id' => $organization->id, 'name' => $organization->displayName, 'slug' => $organization->slug],
            'can' => [
                'write' => Gate::allows('logbook.entries.create'),
                'review' => Gate::allows('logbook.entries.review'),
                'manageTasks' => Gate::allows('logbook.tasks.manage'),
                'viewRoutes' => Gate::allows(Tracks::VIEW),
            ],
        ]);
    }

    /**
     * The member's kit. Each sheet names its current version: the phone
     * downloads the versions it does not hold yet.
     */
    public function kit(): JsonResponse
    {
        $sheets = Gate::allows('logbook.entries.create')
            ? Access::writableSheets()->with(['logbook:id,name,icon', 'publishedVersion:id,log_sheet_id,version,published_at,location,recording'])->orderBy('name')->get()
            : collect();

        return response()->json([
            'serverTime' => now()->toIso8601String(),
            'can' => [
                'write' => Gate::allows('logbook.entries.create'),
                'review' => Gate::allows('logbook.entries.review'),
                'manageTasks' => Gate::allows('logbook.tasks.manage'),
            ],
            'limits' => [
                'fileMaxKb' => Attachments::MAX_KILOBYTES,
                'fileTypes' => explode(',', Attachments::MIMES),
                'filesPerField' => Fields::MAX_FILES,
            ],
            'sheets' => $sheets->map(fn (LogSheet $sheet) => [
                'id' => $sheet->id,
                'name' => $sheet->name,
                'code' => $sheet->code,
                'description' => $sheet->description,
                'instructions' => $sheet->instructions,
                'reviewRequired' => $sheet->review_required,
                'logbook' => ['id' => $sheet->logbook->id, 'name' => $sheet->logbook->name, 'icon' => $sheet->logbook->icon],
                'version' => [
                    'id' => $sheet->publishedVersion?->id,
                    'version' => $sheet->publishedVersion?->version,
                ],
                // GPS (ADR-0028): null when the sheet takes no location.
                'location' => Tracks::settings($sheet->publishedVersion),
                // ADR-0030: scheduled, event or on_demand.
                'recording' => ['mode' => Recording::mode($sheet), 'shifts' => Recording::settings($sheet->publishedVersion)['shifts'] ?? 'off'],
            ])->values()->all(),
            // For person fields: id and name only.
            'members' => User::query()->whereIn('id', WorkspaceMember::query()->select('user_id'))->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            // For resource fields (ADR-0026 §2.10): active resources only.
            'resources' => LogResource::query()->where('active', true)->with('location.parent')->orderBy('code')->get()
                ->map(fn (LogResource $r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'location' => $r->location?->path()])->values()->all(),
            // ADR-0030: active shifts; logbook null means every logbook. Members only suggest.
            'shifts' => Shift::query()->where('active', true)->with('members:id')->orderBy('starts_time')->get()
                ->map(fn (Shift $s) => ['id' => $s->id, 'name' => $s->name, 'starts' => $s->starts_time, 'ends' => $s->ends_time, 'logbookId' => $s->logbook_id, 'members' => $s->members->pluck('id')->values()->all()])->values()->all(),
        ]);
    }

    /**
     * A published template version, when its sheet is within reach.
     */
    public function version(string $version): JsonResponse
    {
        $version = LogSheetVersion::query()->where('status', 'published')->whereKey($version)->firstOrFail();
        Access::sheet($version->log_sheet_id);

        return response()->json([
            'id' => $version->id,
            'sheetId' => $version->log_sheet_id,
            'version' => $version->version,
            'publishedAt' => self::time($version->published_at),
            'fields' => $version->fields,
            'location' => Tracks::settings($version),
        ])->header('Cache-Control', 'no-store, private');
    }
}
