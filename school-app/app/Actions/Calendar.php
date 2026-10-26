<?php

namespace App\Actions;

use App\Enums\SchoolCategory;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Setting the school up (its category and first academic year) and adding
 * academic years later. A year is split evenly into the category's terms or
 * semesters, each starting with the category's assessments.
 */
class Calendar
{
    /** Primary subjects a new primary school starts with. */
    private const PRIMARY_SUBJECTS = [
        'ENG' => 'English',
        'MATH' => 'Mathematics',
        'SCI' => 'Science',
        'SST' => 'Social Studies',
        'RE' => 'Religious Education',
        'ARB' => 'Arabic',
    ];

    /**
     * @throws ValidationException
     */
    public function setUp(SchoolCategory $category, string $yearName, CarbonImmutable $startsOn, CarbonImmutable $endsOn, bool $withDefaults): Workspace
    {
        return DB::transaction(function () use ($category, $yearName, $startsOn, $endsOn, $withDefaults) {
            $workspace = Workspace::query()->lockForUpdate()->first() ?? throw ValidationException::withMessages(['category' => 'This workspace is not provisioned yet.']);

            if ($workspace->category !== null) {
                throw ValidationException::withMessages(['category' => "The school is already set up as a {$workspace->category->label()}."]);
            }

            $workspace->forceFill(['category' => $category])->save();
            $year = $this->addYear($category, $yearName, $startsOn, $endsOn);
            $workspace->forceFill(['current_term_id' => $this->termOn($year, today())->id])->save();

            if ($withDefaults && $category === SchoolCategory::Primary) {
                $this->primaryDefaults();
            }

            return $workspace;
        });
    }

    /**
     * A new academic year with its terms or semesters (inside a transaction).
     *
     * @throws ValidationException
     */
    public function addYear(SchoolCategory $category, string $name, CarbonImmutable $startsOn, CarbonImmutable $endsOn): AcademicYear
    {
        $name = trim($name);
        if (AcademicYear::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "There is already an academic year {$name}."]);
        }
        if ($endsOn->lte($startsOn) || $startsOn->diffInDays($endsOn) < 30) {
            throw ValidationException::withMessages(['ends_on' => 'The year must end at least a month after it starts.']);
        }
        if ($startsOn->diffInDays($endsOn) > 400) {
            throw ValidationException::withMessages(['ends_on' => 'An academic year is at most about a year long.']);
        }
        if (AcademicYear::query()->where('starts_on', '<=', $endsOn)->where('ends_on', '>=', $startsOn)->exists()) {
            throw ValidationException::withMessages(['starts_on' => 'These dates overlap another academic year.']);
        }

        $year = AcademicYear::query()->create(['name' => $name, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);

        // Even periods; the last one ends with the year.
        $periods = $category->periodsPerYear();
        $days = (int) $startsOn->diffInDays($endsOn) + 1;
        $start = $startsOn;
        for ($sequence = 1; $sequence <= $periods; $sequence++) {
            $end = $sequence === $periods ? $endsOn : $startsOn->addDays(intdiv($days * $sequence, $periods) - 1);
            $term = $year->terms()->create([
                'name' => "{$category->periodLabel()} {$sequence}",
                'sequence' => $sequence,
                'starts_on' => $start,
                'ends_on' => $end,
            ]);
            foreach ($category->defaultAssessments() as $position => $assessment) {
                $term->assessments()->create([...$assessment, 'position' => $position + 1]);
            }
            $start = $end->addDay();
        }

        return $year;
    }

    /**
     * Makes a term the current one: registers, marks and fees default to it.
     */
    public function makeCurrent(Term $term): void
    {
        Workspace::query()->firstOrFail()->forceFill(['current_term_id' => $term->id])->save();
    }

    /**
     * Changes the category while the school has no students yet.
     *
     * @throws ValidationException
     */
    public function changeCategory(SchoolCategory $category): void
    {
        DB::transaction(function () use ($category) {
            $workspace = Workspace::query()->lockForUpdate()->firstOrFail();

            if (Student::query()->exists()) {
                throw ValidationException::withMessages(['category' => 'The school has students: its category can no longer change.']);
            }

            $workspace->forceFill(['category' => $category])->save();
        });
    }

    /**
     * The year's term that contains the day, or its first term.
     */
    private function termOn(AcademicYear $year, CarbonImmutable $day): Term
    {
        $terms = $year->terms()->get();

        return $terms->first(fn (Term $term) => $day->betweenIncluded($term->starts_on, $term->ends_on)) ?? $terms->firstOrFail();
    }

    /**
     * Primary 1 to 8, each taught the common primary subjects.
     */
    private function primaryDefaults(): void
    {
        $subjects = [];
        foreach (self::PRIMARY_SUBJECTS as $code => $name) {
            $subjects[] = Subject::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        }

        for ($level = 1; $level <= SchoolCategory::Primary->maxLevel(); $level++) {
            $class = SchoolClass::query()->create(['name' => SchoolCategory::Primary->levelName($level), 'level' => $level]);
            foreach ($subjects as $subject) {
                ClassSubject::query()->create(['school_class_id' => $class->id, 'subject_id' => $subject->id]);
            }
        }
    }
}
