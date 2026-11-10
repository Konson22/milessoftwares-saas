<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

abstract class Controller
{
    /**
     * The signed-in user (every route calling this is behind the auth middleware).
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
     * To another host (a workspace, or the main domain): a
     * full page load, which Inertia does on a 409 with the location. The
     * flash message travels in the session, shared across subdomains.
     */
    protected function away(Request $request, string $url, ?string $success = null): Response
    {
        if ($success !== null) {
            $request->session()->flash('success', $success);
        }

        return Inertia::location($url);
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
