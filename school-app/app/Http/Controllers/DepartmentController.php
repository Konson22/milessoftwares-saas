<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Faculties (universities) and departments (institutes, colleges and
 * universities): how programmes and staff are organized.
 */
class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $this->category();
        abort_unless($category->usesDepartments(), 404);

        return Inertia::render('departments/index', [
            'faculties' => Faculty::query()->withCount('departments')->orderBy('name')->get()
                ->map(fn (Faculty $faculty) => [
                    'id' => $faculty->id,
                    'name' => $faculty->name,
                    'code' => $faculty->code,
                    'departments' => (int) $faculty->getAttribute('departments_count'),
                ]),
            'departments' => Department::query()
                ->with('faculty:id,name')
                ->withCount(['programmes', 'programmes as active_programmes_count' => fn ($query) => $query->where('active', true)])
                ->orderBy('name')
                ->get()
                ->map(fn (Department $department) => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'faculty' => $department->faculty?->name,
                    'programmes' => (int) $department->getAttribute('active_programmes_count'),
                    'staff' => Staff::query()->where('department_id', $department->id)->where('active', true)->count(),
                    'removable' => (int) $department->getAttribute('programmes_count') === 0 && ! Staff::query()->where('department_id', $department->id)->exists(),
                ]),
            'canManage' => $this->can($request, 'school.academics.manage'),
        ]);
    }

    public function storeFaculty(Request $request): RedirectResponse
    {
        abort_unless($this->category()->usesFaculties(), 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:16'],
        ]);

        $faculty = Faculty::query()->create(['name' => trim($validated['name']), 'code' => $this->text($validated['code'] ?? null)]);

        return back()->with('success', "{$faculty->name} added.");
    }

    public function destroyFaculty(Faculty $faculty): RedirectResponse
    {
        if ($faculty->departments()->exists()) {
            throw ValidationException::withMessages(['faculty' => "{$faculty->name} has departments: move or remove them first."]);
        }

        $faculty->delete();

        return back()->with('success', "{$faculty->name} removed.");
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $category = $this->category();
        abort_unless($category->usesDepartments(), 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:16'],
            'faculty_id' => [$category->usesFaculties() ? 'required' : 'prohibited', 'nullable', 'uuid'],
        ]);
        // Looked up within the workspace: another school's faculty is not found.
        $faculty = isset($validated['faculty_id']) ? Faculty::query()->whereKey($validated['faculty_id'])->first() : null;
        if ($category->usesFaculties() && $faculty === null) {
            throw ValidationException::withMessages(['faculty_id' => 'Choose the faculty.']);
        }

        $department = Department::query()->create([
            'name' => trim($validated['name']),
            'code' => $this->text($validated['code'] ?? null),
            'faculty_id' => $faculty?->id,
        ]);

        return back()->with('success', "{$department->name} added.");
    }

    public function destroyDepartment(Department $department): RedirectResponse
    {
        if (Programme::query()->where('department_id', $department->id)->exists() || Staff::query()->where('department_id', $department->id)->exists()) {
            throw ValidationException::withMessages(['department' => "{$department->name} has programmes or staff: it cannot be removed."]);
        }

        $department->delete();

        return back()->with('success', "{$department->name} removed.");
    }
}
