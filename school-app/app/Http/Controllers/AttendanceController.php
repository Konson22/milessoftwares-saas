<?php

namespace App\Http\Controllers;

use App\Actions\Attendance;
use App\Models\AttendanceEntry;
use App\Models\AttendanceRegister;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Support\Teaching;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registers: choosing a class, day (and subject), marking each student,
 * and the registers taken lately.
 */
class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $this->category();
        $classId = $request->string('class')->toString();
        $subjectId = $request->string('subject')->toString();
        $day = $request->date('date')?->toImmutable() ?? today();

        // Teachers record only for what they teach; viewers see every register.
        $teaching = $this->can($request, 'school.attendance.record') ? Teaching::for($this->member($request)) : null;
        $class = $classId === '' ? null : SchoolClass::query()->whereKey($classId)->first();
        $taught = $class === null ? null : $teaching?->subjectIds($class);
        $subjects = $class === null ? collect() : ClassSubject::query()->where('school_class_id', $class->id)
            ->when($taught !== null, fn ($query) => $query->whereIn('subject_id', $taught ?? []))
            ->with('subject:id,code,name')->get()
            ->map(fn (ClassSubject $row) => ['value' => $row->subject_id, 'label' => $row->subject->name])
            ->sortBy('label')->values();
        $subject = $subjectId === '' || $class === null ? null : Subject::query()->whereKey($subjectId)->first();
        $ready = $class !== null && (! $category->attendanceBySubject() || $subject !== null);

        $register = ! $ready ? null : AttendanceRegister::query()
            ->where('school_class_id', $class->id)
            ->where('subject_id', $subject?->id)
            ->whereDate('taken_on', $day)
            ->with(['entries', 'taker:id,name'])
            ->first();
        $marked = $register?->entries->pluck('status', 'student_id') ?? collect();

        $recent = AttendanceRegister::query()
            ->with(['schoolClass:id,name', 'subject:id,name', 'taker:id,name'])
            ->withCount(['entries as absent_count' => fn ($query) => $query->where('status', AttendanceEntry::ABSENT)])
            ->withCount('entries')
            ->latest('taken_on')
            ->latest()
            ->limit(20)
            ->get();

        return Inertia::render('attendance/index', [
            'filters' => ['class' => $classId, 'subject' => $subjectId, 'date' => $day->toDateString()],
            'classes' => $this->classOptions(only: $teaching?->classIds()),
            'subjects' => $subjects,
            'register' => ! $ready ? null : [
                'className' => $class->name,
                'subjectName' => $subject?->name,
                'takenBy' => $register?->taker->name,
                'students' => $class->enrolled()->get()->map(fn (Student $student) => [
                    'id' => $student->id,
                    'number' => $student->number,
                    'name' => $student->name(),
                    'status' => $marked[$student->id] ?? null,
                ]),
            ],
            'recent' => $recent->map(fn (AttendanceRegister $row) => [
                'id' => $row->id,
                'date' => $row->taken_on->toDateString(),
                'classId' => $row->school_class_id,
                'className' => $row->schoolClass->name,
                'subjectId' => $row->subject_id,
                'subjectName' => $row->subject?->name,
                'takenBy' => $row->taker->name,
                'students' => (int) $row->getAttribute('entries_count'),
                'absent' => (int) $row->getAttribute('absent_count'),
            ]),
            'today' => today()->toDateString(),
            'canRecord' => $teaching !== null && ($class === null || $teaching->teaches($class, $subject)),
            'linked' => $teaching?->linked() ?? true,
        ]);
    }

    public function store(Request $request, Attendance $attendance): RedirectResponse
    {
        $validated = $request->validate([
            'school_class_id' => ['required', 'uuid'],
            'subject_id' => ['nullable', 'uuid'],
            'taken_on' => ['required', 'date'],
            'statuses' => ['required', 'array', 'min:1'],
            'statuses.*' => ['required', 'string'],
        ]);
        $class = SchoolClass::query()->whereKey($validated['school_class_id'])->first() ?? throw ValidationException::withMessages(['school_class_id' => 'Choose a class.']);
        $subject = isset($validated['subject_id']) ? Subject::query()->whereKey($validated['subject_id'])->first() : null;
        abort_unless(Teaching::for($this->member($request))->teaches($class, $subject), 403, "You do not teach this register's class or subject.");

        /** @var array<string, string> $statuses */
        $statuses = $validated['statuses'];
        $register = $attendance->take($class, $subject, CarbonImmutable::parse($validated['taken_on']), $statuses, $this->member($request));
        $absent = collect($statuses)->filter(fn (string $status) => $status === AttendanceEntry::ABSENT)->count();

        return to_route('attendance.index', ['class' => $class->id, 'subject' => $subject?->id, 'date' => $register->taken_on->toDateString()])
            ->with('success', "Register saved for {$class->name}: {$absent} absent.");
    }
}
