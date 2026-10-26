<?php

namespace App\Support;

use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\User;

/**
 * Which classes and subjects a member takes registers and enters marks
 * for. Members who manage academics (and owners) do it for every class;
 * everyone else only as the member of staff with their email: for the
 * whole class they are class teacher of, and for the subjects they teach
 * elsewhere.
 */
final class Teaching
{
    private function __construct(
        private readonly bool $everything,
        private readonly ?Staff $staff,
    ) {}

    public static function for(User $user): self
    {
        if ($user->can('school.academics.manage')) {
            return new self(true, null);
        }

        $staff = Staff::query()
            ->where('active', true)
            ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
            ->first();

        return new self(false, $staff);
    }

    public function everything(): bool
    {
        return $this->everything;
    }

    /**
     * Whether the member is a known member of staff (or needs not be).
     */
    public function linked(): bool
    {
        return $this->everything || $this->staff !== null;
    }

    /**
     * The classes the member teaches; null for every class.
     *
     * @return list<string>|null
     */
    public function classIds(): ?array
    {
        if ($this->everything) {
            return null;
        }
        if ($this->staff === null) {
            return [];
        }

        return array_values(array_unique([
            ...SchoolClass::query()->where('class_teacher_id', $this->staff->id)->pluck('id')->all(),
            ...ClassSubject::query()->where('teacher_id', $this->staff->id)->pluck('school_class_id')->all(),
        ]));
    }

    /**
     * The class's subjects the member teaches; null for all of them.
     *
     * @return list<string>|null
     */
    public function subjectIds(SchoolClass $class): ?array
    {
        if ($this->everything || ($this->staff !== null && $class->class_teacher_id === $this->staff->id)) {
            return null;
        }
        if ($this->staff === null) {
            return [];
        }

        return array_values(ClassSubject::query()
            ->where('school_class_id', $class->id)
            ->where('teacher_id', $this->staff->id)
            ->pluck('subject_id')
            ->all());
    }

    /**
     * Whether the member takes this register or enters these marks: the
     * class's daily register (no subject) is the class teacher's.
     */
    public function teaches(SchoolClass $class, ?Subject $subject): bool
    {
        $subjects = $this->subjectIds($class);

        if ($subjects === null) {
            return true;
        }

        return $subject !== null && in_array($subject->id, $subjects, true);
    }
}
