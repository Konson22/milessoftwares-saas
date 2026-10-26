<?php

namespace App\Http\Controllers;

use App\Actions\Calendar;
use App\Enums\SchoolCategory;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Student;
use App\Models\Term;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The school calendar and how it assesses: academic years, their terms or
 * semesters, which one is current, and each term's assessments.
 */
class SettingsController extends Controller
{
    public function index(): Response
    {
        $category = $this->category();
        $current = Workspace::current()?->current_term_id;

        $years = AcademicYear::query()
            ->with(['terms.assessments' => fn ($query) => $query->withCount('marks')])
            ->orderByDesc('starts_on')
            ->get();

        return Inertia::render('settings/index', [
            'category' => $category->value,
            'categories' => SchoolCategory::options(),
            'canChangeCategory' => ! Student::query()->exists(),
            'grading' => $category->gradingScale(),
            'years' => $years->map(fn (AcademicYear $year) => [
                'id' => $year->id,
                'name' => $year->name,
                'startsOn' => $year->starts_on->toDateString(),
                'endsOn' => $year->ends_on->toDateString(),
                'terms' => $year->terms->map(fn (Term $term) => [
                    'id' => $term->id,
                    'name' => $term->name,
                    'startsOn' => $term->starts_on->toDateString(),
                    'endsOn' => $term->ends_on->toDateString(),
                    'current' => $term->id === $current,
                    'assessments' => $term->assessments->map(fn (Assessment $assessment) => [
                        'id' => $assessment->id,
                        'name' => $assessment->name,
                        'weight' => $assessment->weight,
                        'marked' => (int) $assessment->getAttribute('marks_count') > 0,
                    ])->all(),
                ])->all(),
            ])->all(),
        ]);
    }

    public function storeYear(Request $request, Calendar $calendar): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:32'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ]);

        $year = $calendar->addYear($this->category(), $validated['name'], CarbonImmutable::parse($validated['starts_on']), CarbonImmutable::parse($validated['ends_on']));

        return back()->with('success', "Academic year {$year->name} added.");
    }

    public function makeCurrent(Term $term, Calendar $calendar): RedirectResponse
    {
        $calendar->makeCurrent($term);
        $term->load('academicYear');

        return back()->with('success', "{$term->title()} is now the current ".strtolower($this->category()->periodLabel()).'.');
    }

    public function updateTerm(Request $request, Term $term): RedirectResponse
    {
        $validated = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ]);
        $year = $term->academicYear;
        $startsOn = CarbonImmutable::parse($validated['starts_on']);
        $endsOn = CarbonImmutable::parse($validated['ends_on']);

        if ($startsOn->lt($year->starts_on) || $endsOn->gt($year->ends_on)) {
            throw ValidationException::withMessages(['starts_on' => "Keep the dates within {$year->name} ({$year->starts_on->toDateString()} to {$year->ends_on->toDateString()})."]);
        }
        if (Term::query()->where('academic_year_id', $year->id)->whereKeyNot($term->id)->where('starts_on', '<=', $endsOn)->where('ends_on', '>=', $startsOn)->exists()) {
            throw ValidationException::withMessages(['starts_on' => 'These dates overlap another '.strtolower($this->category()->periodLabel()).' of the year.']);
        }

        $term->forceFill(['starts_on' => $startsOn, 'ends_on' => $endsOn])->save();

        return back()->with('success', "{$term->name} dates saved.");
    }

    public function storeAssessment(Request $request, Term $term): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'weight' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $used = (int) $term->assessments()->sum('weight');

        if ($used + (int) $validated['weight'] > 100) {
            throw ValidationException::withMessages(['weight' => 'The weights of a '.strtolower($this->category()->periodLabel()).' add up to at most 100: '.(100 - $used).' is left.']);
        }

        $term->assessments()->create([
            'name' => trim($validated['name']),
            'weight' => (int) $validated['weight'],
            'position' => (int) $term->assessments()->max('position') + 1,
        ]);

        return back()->with('success', "{$validated['name']} added to {$term->name}.");
    }

    public function destroyAssessment(Assessment $assessment): RedirectResponse
    {
        if ($assessment->marks()->exists()) {
            throw ValidationException::withMessages(['assessment' => "{$assessment->name} has marks: it can no longer be removed."]);
        }

        $assessment->delete();

        return back()->with('success', "{$assessment->name} removed.");
    }

    public function updateCategory(Request $request, Calendar $calendar): RedirectResponse
    {
        $validated = $request->validate(['category' => ['required', Rule::enum(SchoolCategory::class)]]);
        $category = SchoolCategory::from($validated['category']);

        $calendar->changeCategory($category);

        return back()->with('success', "The school is now a {$category->label()}.");
    }
}
