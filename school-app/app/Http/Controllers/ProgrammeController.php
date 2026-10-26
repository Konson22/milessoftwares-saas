<?php

namespace App\Http\Controllers;

use App\Models\Department;
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
 * Programmes of institutes, colleges and universities: certificates,
 * diplomas and degrees, with a class for each year of study.
 */
class ProgrammeController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($this->category()->usesProgrammes(), 404);
        $awards = collect($this->category()->awards())->pluck('label', 'value');

        $programmes = Programme::query()
            ->with('department:id,name')
            ->withCount('classes')
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        return Inertia::render('programmes/index', [
            'programmes' => $programmes->map(fn (Programme $programme) => [
                'id' => $programme->id,
                'code' => $programme->code,
                'name' => $programme->name,
                'award' => $awards[$programme->award] ?? $programme->award,
                'durationYears' => $programme->duration_years,
                'department' => $programme->department?->name,
                'classes' => (int) $programme->getAttribute('classes_count'),
                'students' => Student::query()->where('status', Student::ACTIVE)
                    ->whereIn('school_class_id', SchoolClass::query()->where('programme_id', $programme->id)->select('id'))
                    ->count(),
                'active' => $programme->active,
            ]),
            'canManage' => $this->can($request, 'school.academics.manage'),
        ]);
    }

    public function create(): Response
    {
        abort_unless($this->category()->usesProgrammes(), 404);

        return Inertia::render('programmes/form', ['programme' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $programme = new Programme;
        $this->save($request, $programme);

        // Each year of study is a class students sit in.
        if ($request->boolean('with_classes')) {
            for ($year = 1; $year <= $programme->duration_years; $year++) {
                SchoolClass::query()->create(['name' => "{$programme->code} Year {$year}", 'level' => $year, 'programme_id' => $programme->id]);
            }
        }

        return to_route('programmes.index')->with('success', "{$programme->name} added.");
    }

    public function edit(Programme $programme): Response
    {
        return Inertia::render('programmes/form', [
            'programme' => [
                'id' => $programme->id,
                'code' => $programme->code,
                'name' => $programme->name,
                'award' => $programme->award,
                'durationYears' => $programme->duration_years,
                'departmentId' => $programme->department_id,
                'active' => $programme->active,
            ],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Programme $programme): RedirectResponse
    {
        $this->save($request, $programme);

        return to_route('programmes.index')->with('success', "{$programme->name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'awards' => $this->category()->awards(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $department) => ['value' => $department->id, 'label' => $department->name]),
        ];
    }

    private function save(Request $request, Programme $programme): void
    {
        $category = $this->category();
        abort_unless($category->usesProgrammes(), 404);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'award' => ['required', Rule::in(array_column($category->awards(), 'value'))],
            'duration_years' => ['required', 'integer', 'min:1', 'max:'.$category->maxLevel()],
            'department_id' => ['nullable', 'uuid'],
            'active' => ['boolean'],
        ]);
        $code = strtoupper(trim($validated['code']));

        if (Programme::query()->where('code', $code)->whereKeyNot($programme->id)->exists()) {
            throw ValidationException::withMessages(['code' => "Another programme is {$code}."]);
        }
        $department = isset($validated['department_id']) ? Department::query()->whereKey($validated['department_id'])->first() : null;
        if (isset($validated['department_id']) && $department === null) {
            throw ValidationException::withMessages(['department_id' => 'Choose a department of the school.']);
        }

        $programme->fill([
            'code' => $code,
            'name' => trim($validated['name']),
            'award' => $validated['award'],
            'duration_years' => (int) $validated['duration_years'],
            'department_id' => $department?->id,
            'active' => (bool) ($validated['active'] ?? true),
        ])->save();
    }
}
