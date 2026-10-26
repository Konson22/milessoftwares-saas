<?php

namespace App\Http\Controllers;

use App\Actions\Enrolment;
use App\Models\Application;
use App\Models\Programme;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admissions: applications received, then admitted into a class or
 * declined.
 */
class ApplicationController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $this->category();
        $status = $request->string('status')->toString();
        $status = in_array($status, Application::STATUSES, true) ? $status : Application::PENDING;

        $applications = Application::query()
            ->where('status', $status)
            ->with('programme:id,code,name')
            ->latest()
            ->limit(300)
            ->get();

        return Inertia::render('applications/index', [
            'applications' => $applications->map(fn (Application $application) => [
                'id' => $application->id,
                'number' => $application->number,
                'name' => $application->name(),
                'applyingFor' => $this->applyingFor($application),
                'contact' => $application->guardian_phone ?? $application->phone,
                'receivedOn' => $application->created_at?->toDateString(),
                'status' => $application->status,
            ]),
            'filters' => ['status' => $status],
            'counts' => Application::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'levelLabel' => $category->levelLabel(),
            'canManage' => $this->can($request, 'school.students.manage'),
        ]);
    }

    public function create(): Response
    {
        $category = $this->category();

        return Inertia::render('applications/form', [
            'levels' => array_map(fn (int $level) => ['value' => (string) $level, 'label' => $category->levelName($level)], range(1, $category->maxLevel())),
            'programmes' => Programme::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Programme $programme) => ['value' => $programme->id, 'label' => "{$programme->name} ({$programme->code})"]),
        ]);
    }

    public function store(Request $request, Enrolment $enrolment): RedirectResponse
    {
        $category = $this->category();
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::in(Student::GENDERS)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'guardian_name' => [$category->value === 'primary' ? 'required' : 'nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:32'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'level' => ['required', 'integer', 'min:1', 'max:'.$category->maxLevel()],
            'programme_id' => [$category->usesProgrammes() ? 'required' : 'prohibited', 'nullable', 'uuid'],
        ]);

        $programme = isset($validated['programme_id']) ? Programme::query()->whereKey($validated['programme_id'])->where('active', true)->first() : null;
        if ($category->usesProgrammes() && $programme === null) {
            throw ValidationException::withMessages(['programme_id' => 'Choose a programme the school offers.']);
        }

        $application = $enrolment->receive([
            'first_name' => trim($validated['first_name']),
            'last_name' => trim($validated['last_name']),
            'gender' => $validated['gender'],
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'phone' => $this->text($validated['phone'] ?? null),
            'email' => $this->text($validated['email'] ?? null),
            'guardian_name' => $this->text($validated['guardian_name'] ?? null),
            'guardian_phone' => $this->text($validated['guardian_phone'] ?? null),
            'previous_school' => $this->text($validated['previous_school'] ?? null),
            'level' => (int) $validated['level'],
            'programme_id' => $programme?->id,
        ], $this->member($request));

        return to_route('applications.show', $application)->with('success', "Application {$application->number} received.");
    }

    public function show(Request $request, Application $application): Response
    {
        $application->load(['programme:id,code,name', 'student:id,number', 'decider:id,name']);

        // Classes the applicant could join: their level (of their programme) first.
        $classes = SchoolClass::query()
            ->where('active', true)
            ->when($application->programme_id !== null, fn ($query) => $query->where('programme_id', $application->programme_id))
            ->withCount(['students as enrolled_count' => fn ($query) => $query->where('status', Student::ACTIVE)])
            ->orderByRaw('case when level = ? then 0 else 1 end', [$application->level])
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        return Inertia::render('applications/show', [
            'application' => [
                'id' => $application->id,
                'number' => $application->number,
                'name' => $application->name(),
                'gender' => $application->gender,
                'dateOfBirth' => $application->date_of_birth?->toDateString(),
                'phone' => $application->phone,
                'email' => $application->email,
                'guardianName' => $application->guardian_name,
                'guardianPhone' => $application->guardian_phone,
                'previousSchool' => $application->previous_school,
                'applyingFor' => $this->applyingFor($application),
                'status' => $application->status,
                'receivedOn' => $application->created_at?->toDateString(),
                'decidedBy' => $application->decider?->name,
                'decidedAt' => $application->decided_at?->toIso8601String(),
                'decisionNote' => $application->decision_note,
                'student' => $application->student === null ? null : ['id' => $application->student->id, 'number' => $application->student->number],
            ],
            'classes' => $classes->map(fn (SchoolClass $class) => [
                'value' => $class->id,
                'label' => $class->name.($class->capacity !== null ? " ({$class->getAttribute('enrolled_count')}/{$class->capacity})" : " ({$class->getAttribute('enrolled_count')})"),
            ]),
            'canDecide' => $this->can($request, 'school.students.manage'),
        ]);
    }

    public function admit(Request $request, Application $application, Enrolment $enrolment): RedirectResponse
    {
        $validated = $request->validate(['school_class_id' => ['required', 'uuid']]);
        $class = SchoolClass::query()->whereKey($validated['school_class_id'])->first() ?? throw ValidationException::withMessages(['school_class_id' => 'Choose a class.']);

        $student = $enrolment->admit($application, $class, $this->member($request));

        return to_route('students.show', $student)->with('success', "{$student->name()} admitted to {$class->name} ({$student->number}).");
    }

    public function decline(Request $request, Application $application, Enrolment $enrolment): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:255']]);

        $enrolment->decline($application, $validated['note'], $this->member($request));

        return back()->with('success', "Application {$application->number} declined.");
    }

    private function applyingFor(Application $application): string
    {
        $level = $this->category()->levelName($application->level);

        return $application->programme === null ? $level : "{$application->programme->name}, {$level}";
    }
}
