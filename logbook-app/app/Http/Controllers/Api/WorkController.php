<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsWork;
use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationController;
use App\Logbook\Attachments;
use App\Logbook\Member;
use App\Logbook\Models\Notification;
use App\Logbook\Models\ScheduleOccurrence;
use App\Logbook\Models\Task;
use App\Logbook\Push\ExpoPush;
use App\Logbook\PushDevices;
use App\Logbook\Schedules;
use App\Logbook\Tasks;
use App\Logbook\WorkFiles;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The phone's work (ADR-0026 §2.4, §2.6, §2.8): the member's tasks, the
 * inspections due today, and notifications. Writes are safe to repeat:
 * starting twice is the same start, a completion carries the phone's key,
 * evidence is deduplicated by hash. A task changed underneath the phone
 * (reassigned, cancelled, already done) answers 409 with a code.
 */
class WorkController extends Controller
{
    use PresentsWork;

    /**
     * Open tasks given to the member, and those finished in the last 14
     * days (so the phone can show what happened to work done offline).
     */
    public function tasks(): JsonResponse
    {
        $member = Member::current();
        $now = CarbonImmutable::now();
        $tasks = Tasks::visible($member)->where('assignee_id', $member->id)
            ->where(fn ($q) => $q->whereIn('status', [...Task::OPEN, 'awaiting_verification'])
                ->orWhere('updated_at', '>=', $now->subDays(14)))
            ->with(['assignee:id,name', 'creator:id,name', 'logbook:id,name', 'sheet:id,name,code', 'resource:id,code,name', 'files', 'exception'])
            ->orderByRaw('case when due_at is null then 1 else 0 end')->orderBy('due_at')
            ->limit(200)->get();

        return response()->json([
            'serverTime' => $now->toIso8601String(),
            'tasks' => $tasks->map(fn (Task $t) => self::taskDetail($t, $member))->values()->all(),
        ]);
    }

    public function task(string $task): JsonResponse
    {
        $task = Tasks::find($task);

        return response()->json([...self::taskDetail($task, Member::current()), 'history' => self::history('task', $task->id)]);
    }

    public function start(string $task): JsonResponse
    {
        $task = Tasks::start(Tasks::find($task), $this->user());

        return response()->json(self::taskDetail($task, Member::current()));
    }

    /**
     * The completion recorded on the phone, perhaps hours ago: `key` is
     * the phone's id for this completion, `completed_at` when the work was
     * done.
     */
    public function complete(Request $request, string $task): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'completed_at' => ['nullable', 'date'],
            'completion_entry_id' => ['nullable', 'uuid'],
        ]);
        $task = Tasks::find($task);
        if ($task->completion_key === $data['key']) {
            return response()->json(self::taskDetail($task, Member::current()));
        }
        if (! $task->isOpen() || $task->assignee_id !== $this->user()->id) {
            return response()->json([
                'code' => $task->assignee_id !== $this->user()->id ? 'not_assignee' : 'not_open',
                'message' => $task->assignee_id !== $this->user()->id ? 'This task was given to someone else.' : 'This task is '.Tasks::statusLabel($task->status).'.',
                'revision' => $task->revision,
            ], 409);
        }
        $completedAt = isset($data['completed_at']) ? CarbonImmutable::parse($data['completed_at'])->toIso8601String() : null;
        $task = Tasks::complete($task, $this->user(), $data['notes'] ?? null, $data['completion_entry_id'] ?? null, $completedAt, $data['key']);

        return response()->json(self::taskDetail($task, Member::current()));
    }

    public function evidence(Request $request, string $task): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:'.Attachments::MAX_KILOBYTES, 'mimes:'.Attachments::MIMES]]);
        $file = WorkFiles::add(Tasks::find($task), 'evidence', $data['file'], $this->user());

        return response()->json(WorkFiles::row($file), $file->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Inspections expected of the member until the end of tomorrow, and
     * those they missed in the last 7 days (for My Work).
     */
    public function due(): JsonResponse
    {
        $member = Member::current();
        $now = CarbonImmutable::now();
        $with = ['sheet:id,name,code', 'logbook:id,name', 'responsible:id,name', 'entry:id,reference'];

        return response()->json([
            'serverTime' => $now->toIso8601String(),
            'occurrences' => Schedules::dueFor($member, $now->addDay()->endOfDay())
                ->with($with)->limit(100)->get()->map(fn (ScheduleOccurrence $o) => self::occurrenceRow($o, $now))->values()->all(),
            'missed' => Schedules::visible($member)->where('status', 'missed')->where('due_at', '>=', $now->subDays(7))
                ->where(fn ($q) => $q->whereNull('responsible_id')->orWhere('responsible_id', $member->id))
                ->with($with)->orderByDesc('due_at')->limit(20)->get()->map(fn (ScheduleOccurrence $o) => self::occurrenceRow($o, $now))->values()->all(),
        ]);
    }

    /**
     * The phone's push token (ADR-0027 §2.3): registered after sign-in and
     * whenever Expo refreshes it. Safe to repeat.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'install_id' => ['required', 'string', 'max:64'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);
        $device = PushDevices::register($this->user()->id, $data['token'], $data['install_id'], $data['platform'], $data['app_version'] ?? null);

        return response()->json(['id' => $device->id, 'pushEnabled' => ExpoPush::enabled()], $device->wasRecentlyCreated ? 201 : 200);
    }

    /** Signing out: this install stops receiving the member's notices. */
    public function unregisterDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['install_id' => ['required', 'string', 'max:64']]);

        return response()->json(['removed' => PushDevices::unregister($this->user()->id, $data['install_id'])]);
    }

    public function notifications(): JsonResponse
    {
        $me = $this->user()->id;

        return response()->json([
            'unread' => Notification::query()->where('user_id', $me)->whereNull('read_at')->count(),
            'notifications' => Notification::query()->where('user_id', $me)->latest('created_at')->limit(50)->get()
                ->map(fn (Notification $n) => NotificationController::row($n))->values()->all(),
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:200'], 'ids.*' => ['uuid'], 'all' => ['boolean']]);
        $query = Notification::query()->where('user_id', $this->user()->id)->whereNull('read_at');
        if (! ($data['all'] ?? false)) {
            $query->whereIn('id', $data['ids'] ?? []);
        }

        return response()->json(['marked' => $query->update(['read_at' => now()])]);
    }
}
