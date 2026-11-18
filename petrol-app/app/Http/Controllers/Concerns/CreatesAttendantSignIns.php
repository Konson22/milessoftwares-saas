<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Petrol\AttendantSignIn;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in part of the attendant forms (Attendants and Staff): a
 * username and the temporary password the attendant replaces at first
 * sign-in, shown once to the manager afterwards.
 */
trait CreatesAttendantSignIns
{
    /**
     * A new sign-in, when the form asks for one.
     *
     * @return array{username: string, password: string}|null
     */
    private function signInInput(Request $request, ?string $linkedUserId): ?array
    {
        if (! $request->boolean('create_sign_in')) {
            return null;
        }

        if (is_string($request->input('username'))) {
            $request->merge(['username' => mb_strtolower(trim($request->input('username')))]);
        }
        $signIn = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{2,31}$/'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ], [
            'username.regex' => __('A username is 3 to 32 letters, digits, dots, dashes or underscores, starting with a letter or digit.'),
        ]);

        if ($linkedUserId !== null) {
            throw ValidationException::withMessages(['user_id' => __('Choose an existing sign-in or create a new one, not both.')]);
        }

        return ['username' => $signIn['username'], 'password' => $signIn['password']];
    }

    /**
     * Make the account, with the Attendant role at the given station.
     *
     * @param  array{username: string, password: string}  $signIn
     */
    private function createSignIn(Request $request, AttendantSignIn $signIns, string $name, array $signIn, string $branchId): User
    {
        $account = $signIns->create($this->member($request), $name, $signIn['username'], $signIn['password'], $branchId);

        // Shown once: the manager hands these over; the password is the one they typed.
        $request->session()->flash('attendantSignIn', [
            'name' => $name,
            'username' => $account['username'],
            'expiresAt' => substr($account['expiresAt'], 0, 10),
            'signInUrl' => $request->getSchemeAndHttpHost(),
            'roleNote' => $account['roleNote'],
        ]);

        return $account['user'];
    }
}
