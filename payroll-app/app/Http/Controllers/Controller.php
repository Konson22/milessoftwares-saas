<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Miles\PlatformClient\Tenancy\CurrentTenant;

abstract class Controller
{
    protected function organizationId(): string
    {
        return (string) app(CurrentTenant::class)->id();
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
