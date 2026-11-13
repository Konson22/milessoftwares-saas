<?php

namespace App\Petrol;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformRefused;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * A sign-in for an attendant who has no email: a platform account with a
 * username and the temporary password the manager typed, with the Attendant
 * role at this station. The attendant chooses their own password the first
 * time they sign in (web or mobile app). The platform decides who may do it:
 * someone who manages access in the workspace.
 */
final class AttendantSignIn
{
    public const ROLE = 'attendant';

    public function __construct(
        private readonly PlatformApi $platform,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * The new account, also kept as a local user so the attendant can be
     * linked to it now (sign-in later fills in the rest).
     *
     * @return array{user: User, username: string, expiresAt: string, roleNote: string|null}
     *
     * @throws ValidationException under username, password or sign_in
     */
    public function create(User $actor, string $name, string $username, string $password, string $branchId): array
    {
        try {
            $account = $this->platform->addMemberAccount(
                $this->tenant->require()->id,
                $actor->id,
                $name,
                $username,
                $password,
                self::ROLE,
                $branchId,
            );
        } catch (PlatformRefused $refused) {
            $errors = [];
            foreach ($refused->errors as $field => $messages) {
                $errors[in_array($field, ['username', 'password'], true) ? $field : 'sign_in'][] = $messages[0] ?? $refused->getMessage();
            }

            throw ValidationException::withMessages($errors !== [] ? $errors : ['sign_in' => $refused->getMessage()]);
        } catch (NotVisible|PlatformUnavailable) {
            throw ValidationException::withMessages(['sign_in' => __('The sign-in could not be created right now. Try again in a moment.')]);
        }

        $user = User::query()->find($account['user_id']) ?? new User;
        $user->forceFill([
            $user->getKeyName() => $account['user_id'],
            'name' => $account['name'],
            // Products keep the sign-in name where the email goes (Identity::signInName()).
            'email' => $account['username'],
        ])->save();

        return [
            'user' => $user,
            'username' => $account['username'],
            'expiresAt' => $account['expires_at'],
            'roleNote' => $account['role_note'],
        ];
    }
}
