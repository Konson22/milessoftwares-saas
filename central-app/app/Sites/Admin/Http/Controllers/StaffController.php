<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Actions\StaffChangeDenied;
use App\Modules\Administration\Actions\StaffDirectory;
use App\Modules\Administration\Actions\StaffInvitations;
use App\Modules\Administration\Models\PlatformRole;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Administration\Models\StaffInvitation;
use App\Modules\Administration\Support\PlatformRoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffDirectory $directory,
        private readonly StaffInvitations $invitations,
    ) {}

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
            'invitations' => $this->invitations->open(),
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

    /**
     * Invites someone by email: the link takes them through a password and
     * two-factor authentication, and only then makes them staff with the
     * chosen role. Staff added before who never set up two-factor are
     * invited the same way.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', Rule::in(array_keys(PlatformRoleCatalog::roles()))],
        ]);

        try {
            $this->invitations->invite($validated['email'], $validated['name'] ?? null, $validated['role'] ?? null, $this->actor($request));
        } catch (StaffChangeDenied $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return $this->done('Invitation sent to '.mb_strtolower($validated['email']).'.');
    }

    public function resendInvitation(Request $request, StaffInvitation $invitation): RedirectResponse
    {
        return $this->attempt(fn () => $this->invitations->resend($invitation, $this->actor($request)), 'Invitation sent again.');
    }

    public function revokeInvitation(Request $request, StaffInvitation $invitation): RedirectResponse
    {
        $this->invitations->revoke($invitation, $this->actor($request));

        return $this->done('Invitation cancelled.');
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
