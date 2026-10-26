<?php

namespace App\Http\Controllers;

use App\Actions\Calendar;
use App\Enums\SchoolCategory;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting the school up once: what kind of school it is and its first
 * academic year.
 */
class SetupController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (Workspace::category() !== null) {
            return to_route('home');
        }

        $year = (int) today()->format('Y');

        return Inertia::render('setup', [
            'categories' => SchoolCategory::options(),
            'defaults' => [
                'name' => (string) $year,
                'startsOn' => "{$year}-02-01",
                'endsOn' => "{$year}-12-15",
            ],
        ]);
    }

    public function store(Request $request, Calendar $calendar): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(SchoolCategory::class)],
            'name' => ['required', 'string', 'max:32'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'with_defaults' => ['boolean'],
        ]);
        $category = SchoolCategory::from($validated['category']);

        $calendar->setUp(
            $category,
            $validated['name'],
            CarbonImmutable::parse($validated['starts_on']),
            CarbonImmutable::parse($validated['ends_on']),
            (bool) ($validated['with_defaults'] ?? false),
        );

        return to_route('home')->with('success', "{$category->label()} set up: the year {$validated['name']} has {$category->periodsPerYear()} ".strtolower($category->periodLabel()).'s.');
    }
}
