<?php

namespace App\Http\Controllers;

use App\Logbook\Departments;
use App\Logbook\Member;
use App\Logbook\Models\Logbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformRefused;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The workspace's departments, from Logbook: listed with their logbooks,
 * added and renamed by a people manager for the whole workspace (Central
 * keeps them and checks it too). Departments are shared with the
 * workspace's other applications, so they are never deleted here.
 */
class DepartmentController extends Controller
{
    public function __construct(private readonly PlatformApi $platform) {}

    public function index(): Response
    {
        $counts = Logbook::query()->where('status', 'active')->whereNotNull('department_id')
            ->selectRaw('department_id, count(*) as total')->groupBy('department_id')->pluck('total', 'department_id');
        $reach = Member::current()->reach(PeopleController::PERMISSION);

        $departments = [];
        foreach (Departments::all() as $department) {
            if ($department->isActive() && ($reach === null || in_array($department->id, $reach, true))) {
                $departments[] = ['id' => $department->id, 'name' => $department->name, 'logbooks' => (int) ($counts[$department->id] ?? 0)];
            }
        }
        usort($departments, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return Inertia::render('departments/index', [
            'departments' => $departments,
            'canEdit' => $reach === null,
            'workspaceLogbooks' => Logbook::query()->where('status', 'active')->whereNull('department_id')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canEdit(), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        return $this->call(fn () => $this->platform->createDepartment($this->organizationId(), $this->user()->id, trim($data['name'])), "Department {$data['name']} added.");
    }

    public function update(Request $request, string $department): RedirectResponse
    {
        abort_unless($this->canEdit(), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        abort_unless(collect(Departments::all())->contains(fn ($d) => $d->id === $department), 404);

        return $this->call(fn () => $this->platform->renameDepartment($this->organizationId(), $this->user()->id, $department, trim($data['name'])), 'Department renamed.');
    }

    private function canEdit(): bool
    {
        return Member::current()->everywhere(PeopleController::PERMISSION);
    }

    private function call(\Closure $change, string $success): RedirectResponse
    {
        try {
            $change();
        } catch (PlatformRefused $e) {
            $errors = $e->errors === [] ? ['name' => [$e->getMessage()]] : $e->errors;

            return back()->withErrors(['name' => collect($errors)->flatten()->first()])->withInput();
        } catch (NotVisible|PlatformUnavailable) {
            return back()->withErrors(['name' => 'The platform could not be reached. Try again in a moment.']);
        }

        return back()->with('success', $success);
    }

    private function organizationId(): string
    {
        return app(CurrentTenant::class)->require()->id;
    }
}
