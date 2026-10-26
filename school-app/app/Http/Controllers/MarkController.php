<?php

namespace App\Http\Controllers;

use App\Actions\Marks;
use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Support\Teaching;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entering marks: a term's assessment, a class and a subject, then each
 * student's score out of 100.
 */
class MarkController extends Controller
{
    public function index(Request $request): Response
    {
        $term = $this->term($request);
        $assessments = $term?->assessments()->get() ?? collect();
        $assessmentId = $request->string('assessment')->toString();
        $assessment = $assessments->firstWhere('id', $assessmentId) ?? $assessments->first();
        $teaching = Teaching::for($this->member($request));
        $classId = $request->string('class')->toString();
        $class = $classId === '' ? null : SchoolClass::query()->whereKey($classId)->first();
        $subjectId = $request->string('subject')->toString();
        $taught = $class === null ? null : $teaching->subjectIds($class);
        $subjects = $class === null ? collect() : ClassSubject::query()->where('school_class_id', $class->id)
            ->when($taught !== null, fn ($query) => $query->whereIn('subject_id', $taught ?? []))
            ->with(['subject:id,code,name', 'teacher:id,name'])->get()
            ->sortBy(fn (ClassSubject $row) => $row->subject->name)->values();
        $subject = $subjects->first(fn (ClassSubject $row) => $row->subject_id === $subjectId)?->subject;

        $scores = $assessment === null || $class === null || $subject === null ? collect() : Mark::query()
            ->where('assessment_id', $assessment->id)
            ->where('subject_id', $subject->id)
            ->pluck('score', 'student_id');

        return Inertia::render('marks/index', [
            'filters' => [
                'term' => $term?->id,
                'assessment' => $assessment?->id,
                'class' => $classId,
                'subject' => $subject?->id,
            ],
            'terms' => $this->termOptions(),
            'assessments' => $assessments->map(fn (Assessment $row) => ['value' => $row->id, 'label' => "{$row->name} ({$row->weight}%)"]),
            'classes' => $this->classOptions(only: $teaching->classIds()),
            'subjects' => $subjects->map(fn (ClassSubject $row) => [
                'value' => $row->subject_id,
                'label' => $row->subject->name.($row->teacher !== null ? " · {$row->teacher->name}" : ''),
            ]),
            'sheet' => $assessment === null || $class === null || $subject === null ? null : [
                'title' => "{$subject->name}, {$class->name}: {$assessment->name}",
                'students' => $class->enrolled()->get()->map(fn (Student $student) => [
                    'id' => $student->id,
                    'number' => $student->number,
                    'name' => $student->name(),
                    // "85.50" → "85.5"
                    'score' => isset($scores[$student->id]) ? (string) (float) $scores[$student->id] : '',
                ]),
            ],
            'canRecord' => true,
            'linked' => $teaching->linked(),
        ]);
    }

    public function store(Request $request, Marks $marks): RedirectResponse
    {
        $validated = $request->validate([
            'assessment_id' => ['required', 'uuid'],
            'school_class_id' => ['required', 'uuid'],
            'subject_id' => ['required', 'uuid'],
            'scores' => ['required', 'array', 'min:1'],
            'scores.*' => ['nullable', 'string', 'max:6'],
        ]);
        $assessment = Assessment::query()->whereKey($validated['assessment_id'])->first() ?? throw ValidationException::withMessages(['assessment_id' => 'Choose an assessment.']);
        $class = SchoolClass::query()->whereKey($validated['school_class_id'])->first() ?? throw ValidationException::withMessages(['school_class_id' => 'Choose a class.']);
        $subject = Subject::query()->whereKey($validated['subject_id'])->first() ?? throw ValidationException::withMessages(['subject_id' => 'Choose a subject.']);
        abort_unless(Teaching::for($this->member($request))->teaches($class, $subject), 403, 'You do not teach this subject to this class.');

        /** @var array<string, string|null> $scores */
        $scores = $validated['scores'];
        $recorded = $marks->record($assessment, $class, $subject, $scores, $this->member($request));

        return back()->with('success', "{$recorded} ".($recorded === 1 ? 'mark' : 'marks')." saved for {$subject->name}, {$class->name}.");
    }
}
