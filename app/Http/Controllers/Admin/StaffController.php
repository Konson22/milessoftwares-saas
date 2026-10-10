<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administration\Actions\StaffChangeDenied;
use App\Modules\Administration\Actions\StaffDirectory;
use App\Modules\Administration\Models\PlatformRole;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Administration\Support\PlatformRoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(private readonly StaffDirectory $directory) {}

    public function index(Request $request): Response
    {
        PlatformRoleCatalog::sync();
        $current = $this->actor($request);

        return Inertia::render('admin/staff/index', [
            'staff' => PlatformStaff::query()
                ->with(['user:id,name,email,two_factor_confirmed_at', 'roles:id,key,name'])
                ->orderBy('created_at')
                ->get()
                ->map(fn (PlatformStaff $staff) => [
                    'id' => $staff->id,
                    'name' => $staff->user->name,
                    'email' => $staff->user->email,
                    'status' => $staff->status,
                    'mfa' => $staff->user->two_factor_confirmed_at !== null,
                    'roles' => $staff->roles->map(fn (PlatformRole $role) => ['key' => $role->key, 'name' => $role->name])->all(),
                    'isYou' => $staff->is($current),
                ])
                ->all(),
            'roles' => collect(PlatformRoleCatalog::roles())
                ->map(fn (array $role, string $key) => [
                    'key' => $key,
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'permissions' => array_map(fn ($permission) => $permission->value, $role['permissions']),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null) {
            throw ValidationException::withMessages(['email' => 'They need an account first: ask them to register, then add them here.']);
        }

        $this->directory->add($user, $this->actor($request));

        return $this->done('Staff member added. Give them a role next.');
    }

    public function grantRole(Request $request, PlatformStaff $staff): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::in(array_keys(PlatformRoleCatalog::roles()))]]);

        return $this->attempt(fn () => $this->directory->grantRole($staff, $validated['role'], $this->actor($request)), 'Role granted.');
    }

    public function revokeRole(Request $request, PlatformStaff $staff, string $role): RedirectResponse
    {
        return $this->attempt(fn () => $this->directory->revokeRole($staff, $role, $this->actor($request)), 'Role removed.');
    }

    public function updateStatus(Request $request, PlatformStaff $staff): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'removed'])]]);

        return $this->attempt(fn () => $this->directory->setStatus($staff, $validated['status'], $this->actor($request)), 'Status updated.');
    }

    private function actor(Request $request): PlatformStaff
    {
        $staff = $request->attributes->get('staff');
        abort_unless($staff instanceof PlatformStaff, 403);

        return $staff;
    }

    private function attempt(callable $change, string $message): RedirectResponse
    {
        try {
            $change();
        } catch (StaffChangeDenied $e) {
            throw ValidationException::withMessages(['staff' => $e->getMessage()]);
        }

        return $this->done($message);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return back();
    }
}
