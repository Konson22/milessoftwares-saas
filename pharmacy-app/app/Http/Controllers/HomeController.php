<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $workspace = Workspace::current();

        return Inertia::render('home', [
            'settings' => $workspace === null ? null : [
                'currency' => $workspace->currency,
                'timezone' => $workspace->timezone,
            ],
        ]);
    }
}
