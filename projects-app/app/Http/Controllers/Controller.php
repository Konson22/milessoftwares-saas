<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The signed-in member (every route is behind the platform middleware).
     */
    protected function member(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * Whether the member holds a catalog permission, for showing actions.
     */
    protected function can(Request $request, string $permission): bool
    {
        return $request->user()?->can($permission) ?? false;
    }

    /**
     * Trimmed text, or null when blank.
     */
    protected function text(mixed $value): ?string
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));

        return $text === '' ? null : $text;
    }
}
