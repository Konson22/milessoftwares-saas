<?php

namespace App\Http\Controllers;

use App\Actions\Enrolment;
use App\Models\ClassSubject;
use App\Models\Mark;
use App\Models\Programme;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Classes: primary classes and streams, or each programme's years of
 * study. What they are taught and by whom, and promotion at year end.
 */
class ClassController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $this->category();

        $classes = SchoolClass::query()
            ->with(['programme:id,code,name', 'classTeacher:id,name'])
            ->withCount(['students as enrolled_count' => fn ($query) => $query->where('status', Student::ACTIVE), 'classSubjects'])
            ->orderByDesc('active')
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        return Inertia::render('classes/index', [
            'classes' => $classes->map(fn (SchoolClass $class) => [
                'id' => $class->id,
                'name' => $class->name,
                'level' => $category->levelName($class->level),
                'programme' => $class->programme?->name,
                'classTeacher' => $class->classTeacher?->name,
                'enrolled' => (int) $class->getAttribute('enrolled_count'),
                'capacity' => $class->capacity,
                'subjects' => (int) $class->getAttribute('class_subjects_count'),
                'active' => $class->active,
            ]),
            'canManage' => $this->can($request, 'school.academics.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('classes/form', ['schoolClass' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $class = new SchoolClass;
        $this->save($request, $class);

        return to_route('classes.show', $class)->with('success', "{$class->name} added.");
    }

    public function show(Request $request, SchoolClass $class): Response
    {
        $category = $this->category();
        $class->load(['programme:id,code,name', 'classTeacher:id,name']);
        $taught = ClassSubject::query()->where('school_class_id', $class->id)->with(['subject', 'teacher:id,name'])->get()
            ->sortBy(fn (ClassSubject $row) => $row->subject->name)->values();

        // Where students can move on to: the next level (of the same programme).
        $next = SchoolClass::query()
            ->where('active', true)
            ->whereKeyNot($class->id)
            ->where('programme_id', $class->programme_id)
            ->orderBy('level')->orderBy('name')
            ->get(['id', 'name', 'level']);

        return Inertia::render('classes/show', [
            'schoolClass' => [
                'id' => $class->id,
                'name' => $class->name,
                'level' => $category->levelName($class->level),
                'programme' => $class->programme?->name,
                'classTeacher' => $class->classTeacher?->name,
                'capacity' => $class->capacity,
                'active' => $class->active,
                'finalYear' => $class->programme === null ? $class->level >= $category->maxLevel() : $class->level >= Programme::query()->whereKey($class->programme_id)->value('duration_years'),
            ],
            'students' => $class->enrolled()->get()->map(fn (Student $student) => [
                'id' => $student->id,
                'number' => $student->number,
                'name' => $student->name(),
                'gender' => $student->gender,
                'guardianPhone' => $student->guardian_phone ?? $student->phone,
            ]),
            'subjects' => $taught->map(fn (ClassSubject $row) => [
                'id' => $row->id,
                'subjectId' => $row->subject_id,
                'code' => $row->subject->code,
                'name' => $row->subject->name,
                'credits' => $row->subject->credit_hours,
                'teacherId' => $row->teacher_id,
                'teacher' => $row->teacher?->name,
            ]),
            'subjectOptions' => collect($this->subjectOptions())->whereNotIn('value', $taught->pluck('subject_id'))->values(),
            'staffOptions' => $this->staffOptions(),
            'promoteTo' => $next->map(fn (SchoolClass $other) => ['value' => $other->id, 'label' => $other->name, 'level' => $other->level]),
            'can' => [
                'manage' => $this->can($request, 'school.academics.manage'),
                'promote' => $this->can($request, 'school.students.manage'),
            ],
        ]);
    }

    public function edit(SchoolClass $class): Response
    {
        return Inertia::render('classes/form', [
            'schoolClass' => [
                'id' => $class->id,
                'name' => $class->name,
                'level' => $class->level,
                'programmeId' => $class->programme_id,
                'classTeacherId' => $class->class_teacher_id,
                'capacity' => $class->capacity,
                'active' => $class->active,
            ],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, SchoolClass $class): RedirectResponse
    {
        $this->save($request, $class);

        return to_route('classes.show', $class)->with('success', "{$class->name} saved.");
    }

    public function addSubject(Request $request, SchoolClass $class): RedirectResponse
    {
        $validated = $request->validate([
            'subject_id' => ['required', 'uuid'],
            'teacher_id' => ['nullable', 'uuid'],
        ]);
        $subject = Subject::query()->whereKey($validated['subject_id'])->first() ?? throw ValidationException::withMessages(['subject_id' => 'Choose a subject of the school.']);
        $teacher = $this->teacher($validated['teacher_id'] ?? null);

        if (ClassSubject::query()->where('school_class_id', $class->id)->where('subject_id', $subject->id)->exists()) {
            throw ValidationException::withMessages(['subject_id' => "{$class->name} is already taught {$subject->name}."]);
        }

        ClassSubject::query()->create(['school_class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher?->id]);

        return back()->with('success', "{$subject->name} added to {$class->name}.");
    }

    public function updateSubject(Request $request, SchoolClass $class, ClassSubject $classSubject): RedirectResponse
    {
        abort_unless($classSubject->school_class_id === $class->id, 404);
        $validated = $request->validate(['teacher_id' => ['nullable', 'uuid']]);

        $classSubject->forceFill(['teacher_id' => $this->teacher($validated['teacher_id'] ?? null)?->id])->save();

        return back()->with('success', 'Teacher saved.');
    }

    public function removeSubject(SchoolClass $class, ClassSubject $classSubject): RedirectResponse
    {
        abort_unless($classSubject->school_class_id === $class->id, 404);
        $subject = $classSubject->subject;

        if (Mark::query()->where('subject_id', $subject->id)->whereIn('student_id', $class->students()->select('id'))->exists()) {
            throw ValidationException::withMessages(['subject' => "{$class->name} has marks in {$subject->name}: it stays on the timetable."]);
        }

        $classSubject->delete();

        return back()->with('success', "{$subject->name} removed from {$class->name}.");
    }

    public function promote(Request $request, SchoolClass $class, Enrolment $enrolment): RedirectResponse
    {
        $validated = $request->validate([
            'to_class_id' => ['nullable', 'uuid'],
            'student_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'student_ids.*' => ['uuid'],
        ]);
        $to = isset($validated['to_class_id']) ? SchoolClass::query()->whereKey($validated['to_class_id'])->first() : null;
        if (isset($validated['to_class_id']) && $to === null) {
            throw ValidationException::withMessages(['to_class_id' => 'Choose a class of the school.']);
        }

        $moved = $enrolment->promote($class, $to, array_values($validated['student_ids']));

        return back()->with('success', $to === null
            ? "{$moved} ".($moved === 1 ? 'student' : 'students').' graduated.'
            : "{$moved} ".($moved === 1 ? 'student' : 'students')." moved to {$to->name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $category = $this->category();

        return [
            'levels' => array_map(fn (int $level) => ['value' => (string) $level, 'label' => $category->levelName($level)], range(1, $category->maxLevel())),
            'programmes' => Programme::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Programme $programme) => ['value' => $programme->id, 'label' => "{$programme->name} ({$programme->code})"]),
            'staff' => $this->staffOptions(),
        ];
    }

    private function save(Request $request, SchoolClass $class): void
    {
        $category = $this->category();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'level' => ['required', 'integer', 'min:1', 'max:'.$category->maxLevel()],
            'programme_id' => [$category->usesProgrammes() ? 'required' : 'prohibited', 'nullable', 'uuid'],
            'class_teacher_id' => ['nullable', 'uuid'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'active' => ['boolean'],
        ]);

        $programme = isset($validated['programme_id']) ? Programme::query()->whereKey($validated['programme_id'])->first() : null;
        if ($category->usesProgrammes() && $programme === null) {
            throw ValidationException::withMessages(['programme_id' => 'Choose the programme.']);
        }
        if ($programme !== null && (int) $validated['level'] > $programme->duration_years) {
            throw ValidationException::withMessages(['level' => "{$programme->name} lasts {$programme->duration_years} ".($programme->duration_years === 1 ? 'year' : 'years').'.']);
        }

        $class->fill([
            'name' => trim($validated['name']),
            'level' => (int) $validated['level'],
            'programme_id' => $programme?->id,
            'class_teacher_id' => $this->teacher($validated['class_teacher_id'] ?? null)?->id,
            'capacity' => isset($validated['capacity']) ? (int) $validated['capacity'] : null,
            'active' => (bool) ($validated['active'] ?? true),
        ])->save();
    }

    /**
     * @throws ValidationException
     */
    private function teacher(?string $id): ?Staff
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Staff::query()->whereKey($id)->where('active', true)->first() ?? throw ValidationException::withMessages(['teacher_id' => 'Choose a member of staff.']);
    }
}
