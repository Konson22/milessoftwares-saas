<?php

namespace App\Http\Controllers;

use App\Actions\Staffing;
use App\Models\ClassSubject;
use App\Models\Department;
use App\Models\SchoolClass;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Teachers, lecturers and other staff, and what they teach.
 */
class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        $staff = Staff::query()->with('department:id,name')->orderByDesc('active')->orderBy('name')->get();
        $teaching = ClassSubject::query()->whereNotNull('teacher_id')->selectRaw('teacher_id, count(*) as lessons')->groupBy('teacher_id')->pluck('lessons', 'teacher_id');
        $classTeachers = SchoolClass::query()->whereNotNull('class_teacher_id')->where('active', true)->pluck('name', 'class_teacher_id');

        return Inertia::render('staff/index', [
            'staff' => $staff->map(fn (Staff $member) => [
                'id' => $member->id,
                'number' => $member->number,
                'name' => $member->name,
                'position' => $member->position,
                'department' => $member->department?->name,
                'phone' => $member->phone,
                'email' => $member->email,
                'teaches' => (int) ($teaching[$member->id] ?? 0),
                'classTeacherOf' => $classTeachers[$member->id] ?? null,
                'active' => $member->active,
            ]),
            'canManage' => $this->can($request, 'school.academics.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/form', ['member' => null, 'today' => today()->toDateString(), ...$this->options()]);
    }

    public function store(Request $request, Staffing $staffing): RedirectResponse
    {
        $member = $staffing->hire([...$this->validated($request), 'started_on' => CarbonImmutable::parse($request->string('started_on')->toString() ?: today()->toDateString())]);

        return to_route('staff.index')->with('success', "{$member->name} added ({$member->number}).");
    }

    public function edit(Staff $staff): Response
    {
        return Inertia::render('staff/form', [
            'member' => [
                'id' => $staff->id,
                'number' => $staff->number,
                'name' => $staff->name,
                'position' => $staff->position,
                'departmentId' => $staff->department_id,
                'phone' => $staff->phone,
                'email' => $staff->email,
                'startedOn' => $staff->started_on->toDateString(),
                'active' => $staff->active,
            ],
            'today' => today()->toDateString(),
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Staff $staff): RedirectResponse
    {
        $validated = $this->validated($request);
        $active = $request->boolean('active', true);

        $staff->forceFill([...$validated, 'active' => $active])->save();

        return to_route('staff.index')->with('success', "{$staff->name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $category = $this->category();

        return [
            'positions' => [$category->staffLabel(), 'Head teacher', 'Deputy head', 'Bursar', 'Registrar', 'Librarian', 'Administrator', 'Support staff'],
            'departments' => $category->usesDepartments()
                ? Department::query()->orderBy('name')->get(['id', 'name'])->map(fn (Department $department) => ['value' => $department->id, 'label' => $department->name])
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:64'],
            'department_id' => ['nullable', 'uuid'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'started_on' => ['nullable', 'date', 'before_or_equal:today'],
            'active' => ['boolean'],
        ]);

        $department = isset($validated['department_id']) ? Department::query()->whereKey($validated['department_id'])->first() : null;
        if (isset($validated['department_id']) && $department === null) {
            throw ValidationException::withMessages(['department_id' => 'Choose a department of the school.']);
        }

        return [
            'name' => trim($validated['name']),
            'position' => trim($validated['position']),
            'department_id' => $department?->id,
            'phone' => $this->text($validated['phone'] ?? null),
            'email' => $this->text($validated['email'] ?? null),
        ];
    }
}
