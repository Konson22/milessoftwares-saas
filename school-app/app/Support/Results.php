<?php

namespace App\Support;

use App\Enums\SchoolCategory;
use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Support\Collection;

/**
 * Term results worked out from marks. A subject's mark is its assessments'
 * scores weighted by their weights (out of 100); while some assessments are
 * unmarked, it is the weighted mean of those marked, flagged incomplete.
 * Grades come from the category's scale; primary schools rank pupils by
 * average, colleges and universities weigh grade points by credit hours.
 *
 * @phpstan-type SubjectResult array{subjectId: string, code: string, name: string, credits: int|null, scores: array<string, float|null>, mark: float|null, complete: bool, grade: string|null, points: float|null, remark: string|null}
 * @phpstan-type StudentResult array{studentId: string, number: string, name: string, subjects: list<SubjectResult>, total: float|null, average: float|null, gpa: float|null, credits: int, position: int|null}
 */
class Results
{
    public function __construct(private readonly SchoolCategory $category) {}

    /**
     * Every enrolled student's results in the class's subjects, ranked
     * (primary schools) by average.
     *
     * @return list<StudentResult>
     */
    public function forClass(Term $term, SchoolClass $class): array
    {
        $students = $class->enrolled()->get();
        $subjects = ClassSubject::query()->where('school_class_id', $class->id)->with('subject')->get()
            ->map(fn (ClassSubject $taught) => $taught->subject)
            ->sortBy('name')->values();

        $results = $this->compute($term, $students, $subjects, $this->marks($term, array_values($students->map(fn (Student $student) => $student->id)->all())));

        return $this->rank($results);
    }

    /**
     * One student's report: the class's subjects and any others they were
     * marked in, with their position in the class.
     *
     * @return StudentResult
     */
    public function forStudent(Term $term, Student $student): array
    {
        $class = $student->schoolClass;
        if ($class !== null && $student->status === Student::ACTIVE) {
            foreach ($this->forClass($term, $class) as $result) {
                if ($result['studentId'] === $student->id && $this->markedOnlyInClassSubjects($term, $student, $class)) {
                    return $result;
                }
            }
        }

        $marks = $this->marks($term, [$student->id]);
        $subjects = Subject::query()->whereIn('id', $marks->pluck('subject_id')->unique())->orderBy('name')->get();

        return $this->compute($term, collect([$student]), $subjects, $marks)[0];
    }

    /**
     * The cumulative GPA over every term up to this one (credits only).
     */
    public function cumulativeGpa(Term $term, Student $student): ?float
    {
        if (! $this->category->usesCredits()) {
            return null;
        }

        $points = 0.0;
        $credits = 0;
        $terms = Term::query()->where('starts_on', '<=', $term->starts_on)
            ->whereIn('id', Assessment::query()->whereIn('id', Mark::query()->where('student_id', $student->id)->select('assessment_id'))->select('term_id'))
            ->get();

        foreach ($terms as $past) {
            foreach ($this->forStudent($past, $student)['subjects'] as $subject) {
                if ($subject['points'] !== null) {
                    $points += $subject['points'] * ($subject['credits'] ?? 1);
                    $credits += $subject['credits'] ?? 1;
                }
            }
        }

        return $credits === 0 ? null : round($points / $credits, 2);
    }

    /**
     * @param  list<string>  $studentIds
     * @return Collection<int, Mark>
     */
    private function marks(Term $term, array $studentIds): Collection
    {
        return Mark::query()
            ->whereIn('assessment_id', $term->assessments()->select('id'))
            ->whereIn('student_id', $studentIds)
            ->get();
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, Subject>  $subjects
     * @param  Collection<int, Mark>  $marks
     * @return list<StudentResult>
     */
    private function compute(Term $term, Collection $students, Collection $subjects, Collection $marks): array
    {
        $assessments = $term->assessments()->get();
        $byKey = $marks->keyBy(fn (Mark $mark) => "{$mark->student_id}|{$mark->subject_id}|{$mark->assessment_id}");

        $results = [];
        foreach ($students as $student) {
            $rows = [];
            foreach ($subjects as $subject) {
                $scores = [];
                $weighted = 0.0;
                $weights = 0;
                foreach ($assessments as $assessment) {
                    $mark = $byKey->get("{$student->id}|{$subject->id}|{$assessment->id}");
                    $scores[$assessment->id] = $mark === null ? null : (float) $mark->score;
                    if ($mark !== null && $assessment->weight > 0) {
                        $weighted += (float) $mark->score * $assessment->weight;
                        $weights += $assessment->weight;
                    }
                }

                $value = $weights === 0 ? null : round($weighted / $weights, 1);
                $band = $value === null ? null : $this->category->grade($value);
                $rows[] = [
                    'subjectId' => $subject->id,
                    'code' => $subject->code,
                    'name' => $subject->name,
                    'credits' => $this->category->usesCredits() ? ($subject->credit_hours ?? 1) : null,
                    'scores' => $scores,
                    'mark' => $value,
                    'complete' => $assessments->isNotEmpty() && ! in_array(null, $scores, true),
                    'grade' => $band['grade'] ?? null,
                    'points' => $band['points'] ?? null,
                    'remark' => $band['remark'] ?? null,
                ];
            }

            $marked = array_values(array_filter($rows, fn (array $row) => $row['mark'] !== null));
            $total = $marked === [] ? null : round(array_sum(array_column($marked, 'mark')), 1);
            $credits = 0;
            $points = 0.0;
            foreach ($marked as $row) {
                if ($row['points'] !== null) {
                    $credits += $row['credits'] ?? 1;
                    $points += $row['points'] * ($row['credits'] ?? 1);
                }
            }

            $results[] = [
                'studentId' => $student->id,
                'number' => $student->number,
                'name' => $student->name(),
                'subjects' => $rows,
                'total' => $total,
                'average' => $marked === [] ? null : round($total / count($marked), 1),
                'gpa' => $credits === 0 ? null : round($points / $credits, 2),
                'credits' => $credits,
                'position' => null,
            ];
        }

        return $results;
    }

    /**
     * Highest average first; equal averages share a position.
     *
     * @param  list<StudentResult>  $results
     * @return list<StudentResult>
     */
    private function rank(array $results): array
    {
        $key = $this->category->usesCredits() ? 'gpa' : 'average';
        usort($results, fn (array $a, array $b) => [$b[$key] ?? -1, $a['name']] <=> [$a[$key] ?? -1, $b['name']]);

        if (! $this->category->ranksInClass()) {
            return $results;
        }

        $position = 0;
        $previous = null;
        foreach ($results as $index => $result) {
            if ($result['average'] === null) {
                continue;
            }
            if ($result['average'] !== $previous) {
                $position = $index + 1;
                $previous = $result['average'];
            }
            $results[$index]['position'] = $position;
        }

        return $results;
    }

    private function markedOnlyInClassSubjects(Term $term, Student $student, SchoolClass $class): bool
    {
        return ! Mark::query()
            ->whereIn('assessment_id', $term->assessments()->select('id'))
            ->where('student_id', $student->id)
            ->whereNotIn('subject_id', ClassSubject::query()->where('school_class_id', $class->id)->select('subject_id'))
            ->exists();
    }
}
