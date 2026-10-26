<?php

namespace App\Actions;

use App\Models\Application;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DocumentNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Students joining, moving up and leaving: applications received and
 * decided, students enrolled in a class (numbered ADM- at primary schools,
 * REG- elsewhere), promoted to the next class or graduated.
 */
class Enrolment
{
    /** Details a student and an application share. */
    public const DETAILS = ['first_name', 'last_name', 'gender', 'date_of_birth', 'phone', 'email', 'guardian_name', 'guardian_phone'];

    public function __construct(private readonly DocumentNumbers $numbers) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function receive(array $details, User $by): Application
    {
        return DB::transaction(fn () => Application::query()->create([
            ...$details,
            'number' => $this->numbers->next('application', 'APP'),
            'status' => Application::PENDING,
            'received_by' => $by->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $details
     *
     * @throws ValidationException
     */
    public function enrol(array $details, SchoolClass $class, CarbonImmutable $admittedOn): Student
    {
        return DB::transaction(function () use ($details, $class, $admittedOn) {
            $this->assertTakes($class);

            $category = Workspace::category() ?? throw ValidationException::withMessages(['school_class_id' => 'Set the school up first.']);

            return Student::query()->create([
                ...$details,
                'number' => $this->numbers->next('student', $category->studentNumberPrefix()),
                'school_class_id' => $class->id,
                'status' => Student::ACTIVE,
                'admitted_on' => $admittedOn,
            ]);
        });
    }

    /**
     * @throws ValidationException
     */
    public function admit(Application $application, SchoolClass $class, User $by): Student
    {
        return DB::transaction(function () use ($application, $class, $by) {
            $locked = $this->pending($application);

            if ($locked->programme_id !== null && $class->programme_id !== $locked->programme_id) {
                throw ValidationException::withMessages(['school_class_id' => 'Choose a class of the programme applied for.']);
            }

            $student = $this->enrol($locked->only(self::DETAILS), $class, today());
            $locked->forceFill([
                'status' => Application::ADMITTED,
                'student_id' => $student->id,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ])->save();

            return $student;
        });
    }

    /**
     * @throws ValidationException
     */
    public function decline(Application $application, string $note, User $by): Application
    {
        return DB::transaction(function () use ($application, $note, $by) {
            $locked = $this->pending($application);
            $locked->forceFill([
                'status' => Application::DECLINED,
                'decision_note' => trim($note),
                'decided_by' => $by->id,
                'decided_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Moves the class's chosen students into the next class, or (without one)
     * graduates them. Returns how many moved.
     *
     * @param  list<string>  $studentIds
     *
     * @throws ValidationException
     */
    public function promote(SchoolClass $from, ?SchoolClass $to, array $studentIds): int
    {
        return DB::transaction(function () use ($from, $to, $studentIds) {
            $students = Student::query()
                ->whereKey($studentIds)
                ->where('school_class_id', $from->id)
                ->where('status', Student::ACTIVE)
                ->lockForUpdate()
                ->get();

            if ($students->count() !== count(array_unique($studentIds))) {
                throw ValidationException::withMessages(['student_ids' => "Choose students currently in {$from->name}."]);
            }
            if ($to !== null) {
                if ($to->id === $from->id) {
                    throw ValidationException::withMessages(['to_class_id' => 'Choose another class.']);
                }
                $this->assertTakes($to, $students->count());
            }

            foreach ($students as $student) {
                $student->forceFill($to === null
                    ? ['status' => Student::GRADUATED]
                    : ['school_class_id' => $to->id])->save();
            }

            return $students->count();
        });
    }

    /**
     * @throws ValidationException
     */
    private function pending(Application $application): Application
    {
        $locked = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== Application::PENDING) {
            throw ValidationException::withMessages(['application' => "Application {$locked->number} is already {$locked->status}."]);
        }

        return $locked;
    }

    /**
     * @throws ValidationException
     */
    private function assertTakes(SchoolClass $class, int $joining = 1): void
    {
        if (! $class->active) {
            throw ValidationException::withMessages(['school_class_id' => "{$class->name} is closed."]);
        }
        if ($class->capacity !== null && $class->enrolled()->count() + $joining > $class->capacity) {
            throw ValidationException::withMessages(['school_class_id' => "{$class->name} has room for {$class->capacity} students."]);
        }
    }
}
