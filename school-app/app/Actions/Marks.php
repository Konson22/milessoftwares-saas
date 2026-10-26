<?php

namespace App\Actions;

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entering a class's marks for one assessment of one subject, out of 100.
 * A blank mark removes one entered before.
 */
class Marks
{
    /**
     * @param  array<string, string|null>  $scores  student id => score
     *
     * @throws ValidationException
     */
    public function record(Assessment $assessment, SchoolClass $class, Subject $subject, array $scores, User $by): int
    {
        return DB::transaction(function () use ($assessment, $class, $subject, $scores, $by) {
            if (! ClassSubject::query()->where('school_class_id', $class->id)->where('subject_id', $subject->id)->exists()) {
                throw ValidationException::withMessages(['subject_id' => "{$class->name} is not taught {$subject->name}."]);
            }

            $enrolled = $class->enrolled()->pluck('id')->all();
            if (array_diff(array_keys($scores), $enrolled) !== []) {
                throw ValidationException::withMessages(['scores' => "Enter marks for students in {$class->name} only."]);
            }

            $recorded = 0;
            foreach ($scores as $studentId => $score) {
                $score = trim((string) $score);
                $existing = Mark::query()
                    ->where('assessment_id', $assessment->id)
                    ->where('subject_id', $subject->id)
                    ->where('student_id', $studentId);

                if ($score === '') {
                    $existing->delete();

                    continue;
                }
                if (! is_numeric($score) || (float) $score < 0 || (float) $score > 100 || preg_match('/^\d{1,3}(\.\d{1,2})?$/', $score) !== 1) {
                    throw ValidationException::withMessages(["scores.{$studentId}" => 'A mark is a number from 0 to 100, with at most two decimals.']);
                }

                $mark = $existing->first() ?? new Mark([
                    'assessment_id' => $assessment->id,
                    'subject_id' => $subject->id,
                    'student_id' => $studentId,
                ]);
                $mark->forceFill(['score' => $score, 'recorded_by' => $by->id])->save();
                $recorded++;
            }

            return $recorded;
        });
    }
}
