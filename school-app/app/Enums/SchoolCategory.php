<?php

namespace App\Enums;

use LogicException;

/**
 * What kind of school the workspace runs, chosen once at setup. It decides
 * the words (term or semester, subject or course), the structure (classes
 * only, or programmes under departments and faculties), how students are
 * numbered and how results are graded.
 */
enum SchoolCategory: string
{
    case Primary = 'primary';
    case Institute = 'institute';
    case College = 'college';
    case University = 'university';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary school',
            self::Institute => 'Institute',
            self::College => 'College',
            self::University => 'University',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Primary => 'Primary 1 to 8, three terms a year, class registers and report cards with class positions.',
            self::Institute => 'Technical and vocational: certificate and diploma programmes, modules, distinction / credit / pass.',
            self::College => 'Diploma programmes in departments, semesters, courses with credit hours and a GPA.',
            self::University => 'Faculties, departments and degree programmes, semesters, credit hours, GPA and CGPA.',
        };
    }

    /** "Term" or "Semester". */
    public function periodLabel(): string
    {
        return $this === self::Primary ? 'Term' : 'Semester';
    }

    public function periodsPerYear(): int
    {
        return $this === self::Primary ? 3 : 2;
    }

    /** What a class's place in the school is called: "Class" (Primary 4) or "Year" (of study). */
    public function levelLabel(): string
    {
        return $this === self::Primary ? 'Class' : 'Year of study';
    }

    public function levelName(int $level): string
    {
        return $this === self::Primary ? "Primary {$level}" : "Year {$level}";
    }

    public function maxLevel(): int
    {
        return $this === self::Primary ? 8 : 7;
    }

    /** "Subject", "Module" or "Course". */
    public function subjectLabel(): string
    {
        return match ($this) {
            self::Primary => 'Subject',
            self::Institute => 'Module',
            self::College, self::University => 'Course',
        };
    }

    public function staffLabel(): string
    {
        return match ($this) {
            self::Primary => 'Teacher',
            self::Institute => 'Instructor',
            self::College, self::University => 'Lecturer',
        };
    }

    public function studentNumberLabel(): string
    {
        return $this === self::Primary ? 'Admission number' : 'Registration number';
    }

    public function studentNumberPrefix(): string
    {
        return $this === self::Primary ? 'ADM' : 'REG';
    }

    /** Students study a programme (and its year), not only sit in a class. */
    public function usesProgrammes(): bool
    {
        return $this !== self::Primary;
    }

    public function usesDepartments(): bool
    {
        return $this !== self::Primary;
    }

    public function usesFaculties(): bool
    {
        return $this === self::University;
    }

    /** Courses carry credit hours and results a grade point average. */
    public function usesCredits(): bool
    {
        return $this === self::College || $this === self::University;
    }

    /** Registers are taken per lesson of a subject; at a primary school, once a day per class. */
    public function attendanceBySubject(): bool
    {
        return $this !== self::Primary;
    }

    /** Report cards rank pupils in their class. */
    public function ranksInClass(): bool
    {
        return $this === self::Primary;
    }

    /**
     * Awards a programme leads to.
     *
     * @return list<array{value: string, label: string}>
     */
    public function awards(): array
    {
        $awards = match ($this) {
            self::Primary => [],
            self::Institute => ['certificate' => 'Certificate', 'diploma' => 'Diploma'],
            self::College => ['certificate' => 'Certificate', 'diploma' => 'Diploma', 'higher_diploma' => 'Higher diploma'],
            self::University => ['diploma' => 'Diploma', 'bachelor' => "Bachelor's degree", 'postgraduate_diploma' => 'Postgraduate diploma', 'master' => "Master's degree", 'doctorate' => 'Doctorate'],
        };

        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($awards), $awards);
    }

    /**
     * The assessments each new term starts with, weights adding up to 100.
     *
     * @return list<array{name: string, weight: int}>
     */
    public function defaultAssessments(): array
    {
        return $this === self::Primary
            ? [['name' => 'Mid-term exam', 'weight' => 30], ['name' => 'End of term exam', 'weight' => 70]]
            : [['name' => 'Coursework', 'weight' => 40], ['name' => 'Final exam', 'weight' => 60]];
    }

    /**
     * The grading scale, highest first: a mark out of 100 at or above "min"
     * earns the grade (and, where credits are used, its grade points).
     *
     * @return list<array{grade: string, min: int, points: float|null, remark: string}>
     */
    public function gradingScale(): array
    {
        return match ($this) {
            self::Primary => [
                ['grade' => 'A', 'min' => 80, 'points' => null, 'remark' => 'Excellent'],
                ['grade' => 'B', 'min' => 70, 'points' => null, 'remark' => 'Very good'],
                ['grade' => 'C', 'min' => 60, 'points' => null, 'remark' => 'Good'],
                ['grade' => 'D', 'min' => 50, 'points' => null, 'remark' => 'Fair'],
                ['grade' => 'E', 'min' => 0, 'points' => null, 'remark' => 'Needs improvement'],
            ],
            self::Institute => [
                ['grade' => 'Distinction', 'min' => 75, 'points' => null, 'remark' => 'Distinction'],
                ['grade' => 'Credit', 'min' => 60, 'points' => null, 'remark' => 'Credit'],
                ['grade' => 'Pass', 'min' => 50, 'points' => null, 'remark' => 'Pass'],
                ['grade' => 'Fail', 'min' => 0, 'points' => null, 'remark' => 'Fail: retake'],
            ],
            self::College, self::University => [
                ['grade' => 'A', 'min' => 80, 'points' => 5.0, 'remark' => 'Excellent'],
                ['grade' => 'B+', 'min' => 75, 'points' => 4.5, 'remark' => 'Very good'],
                ['grade' => 'B', 'min' => 70, 'points' => 4.0, 'remark' => 'Good'],
                ['grade' => 'C+', 'min' => 65, 'points' => 3.5, 'remark' => 'Fairly good'],
                ['grade' => 'C', 'min' => 60, 'points' => 3.0, 'remark' => 'Fair'],
                ['grade' => 'D+', 'min' => 55, 'points' => 2.5, 'remark' => 'Pass'],
                ['grade' => 'D', 'min' => 50, 'points' => 2.0, 'remark' => 'Marginal pass'],
                ['grade' => 'F', 'min' => 0, 'points' => 0.0, 'remark' => 'Fail: retake'],
            ],
        };
    }

    /**
     * @return array{grade: string, min: int, points: float|null, remark: string}
     */
    public function grade(float $mark): array
    {
        $bands = $this->gradingScale();
        foreach ($bands as $index => $band) {
            // The last band takes whatever is left.
            if ($mark >= $band['min'] || $index === count($bands) - 1) {
                return $band;
            }
        }

        throw new LogicException("{$this->value} has no grading scale.");
    }

    /**
     * The words and switches the pages need.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'period' => $this->periodLabel(),
            'level' => $this->levelLabel(),
            'subject' => $this->subjectLabel(),
            'staff' => $this->staffLabel(),
            'studentNumber' => $this->studentNumberLabel(),
            'usesProgrammes' => $this->usesProgrammes(),
            'usesDepartments' => $this->usesDepartments(),
            'usesFaculties' => $this->usesFaculties(),
            'usesCredits' => $this->usesCredits(),
            'attendanceBySubject' => $this->attendanceBySubject(),
            'ranksInClass' => $this->ranksInClass(),
            'maxLevel' => $this->maxLevel(),
        ];
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => ['value' => $category->value, 'label' => $category->label(), 'description' => $category->description()], self::cases());
    }
}
