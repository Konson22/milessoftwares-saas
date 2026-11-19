<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Logbook\Access;
use App\Logbook\Attachments;
use App\Logbook\Fields;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
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
            ? Access::writableSheets()->with(['logbook:id,name,icon', 'publishedVersion:id,log_sheet_id,version,published_at'])->orderBy('name')->get()
            : collect();

        return response()->json([
            'serverTime' => now()->toIso8601String(),
            'can' => [
                'write' => Gate::allows('logbook.entries.create'),
                'review' => Gate::allows('logbook.entries.review'),
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
            ])->values()->all(),
            // For person fields: id and name only.
            'members' => User::query()->whereIn('id', WorkspaceMember::query()->select('user_id'))->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
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
        ])->header('Cache-Control', 'no-store, private');
    }
}
