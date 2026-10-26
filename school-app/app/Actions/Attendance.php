<?php

namespace App\Actions;

use App\Models\AttendanceEntry;
use App\Models\AttendanceRegister;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Taking a register: every student in the class marked present, late,
 * absent or excused for the day (and, outside primary schools, the
 * subject). Taking the same register again replaces its marks.
 */
class Attendance
{
    /**
     * @param  array<string, string>  $statuses  student id => status
     *
     * @throws ValidationException
     */
    public function take(SchoolClass $class, ?Subject $subject, CarbonImmutable $day, array $statuses, User $by): AttendanceRegister
    {
        return DB::transaction(function () use ($class, $subject, $day, $statuses, $by) {
            $category = Workspace::category() ?? throw ValidationException::withMessages(['school_class_id' => 'Set the school up first.']);

            if ($category->attendanceBySubject() && $subject === null) {
                throw ValidationException::withMessages(['subject_id' => "Choose the {$category->subjectLabel()} the register is for."]);
            }
            if ($subject !== null && ! ClassSubject::query()->where('school_class_id', $class->id)->where('subject_id', $subject->id)->exists()) {
                throw ValidationException::withMessages(['subject_id' => "{$class->name} is not taught {$subject->name}."]);
            }
            if ($day->gt(today())) {
                throw ValidationException::withMessages(['taken_on' => 'A register cannot be taken for a day still to come.']);
            }

            $term = Term::query()->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->first()
                ?? throw ValidationException::withMessages(['taken_on' => "No {$category->periodLabel()} covers {$day->toDateString()}."]);

            $enrolled = $class->enrolled()->pluck('id')->all();
            if (array_diff(array_keys($statuses), $enrolled) !== [] || array_diff($enrolled, array_keys($statuses)) !== []) {
                throw ValidationException::withMessages(['statuses' => "Mark every student in {$class->name}, and only them."]);
            }
            foreach ($statuses as $status) {
                if (! in_array($status, AttendanceEntry::STATUSES, true)) {
                    throw ValidationException::withMessages(['statuses' => 'Mark each student present, late, absent or excused.']);
                }
            }

            $register = AttendanceRegister::query()
                ->where('school_class_id', $class->id)
                ->where('subject_id', $subject?->id)
                ->whereDate('taken_on', $day)
                ->lockForUpdate()
                ->first();

            if ($register === null) {
                $register = AttendanceRegister::query()->create([
                    'school_class_id' => $class->id,
                    'subject_id' => $subject?->id,
                    'term_id' => $term->id,
                    'taken_on' => $day,
                    'taken_by' => $by->id,
                ]);
            } else {
                $register->forceFill(['taken_by' => $by->id])->save();
                $register->entries()->delete();
            }

            foreach ($statuses as $studentId => $status) {
                $register->entries()->create(['student_id' => $studentId, 'status' => $status]);
            }

            return $register;
        });
    }
}
