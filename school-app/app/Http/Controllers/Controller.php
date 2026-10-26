<?php

namespace App\Http\Controllers;

use App\Enums\SchoolCategory;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The signed-in member (every route is behind the platform middleware).
     */
    protected function member(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * Whether the member holds a catalog permission, for showing actions.
     */
    protected function can(Request $request, string $permission): bool
    {
        return $request->user()?->can($permission) ?? false;
    }

    /**
     * Trimmed text, or null when blank.
     */
    protected function text(mixed $value): ?string
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));

        return $text === '' ? null : $text;
    }

    /**
     * The school's category (pages behind "school.setup" always have one).
     */
    protected function category(): SchoolCategory
    {
        $category = Workspace::category();
        abort_if($category === null, 409, 'Set the school up first.');

        return $category;
    }

    /**
     * The term asked for (?term=), or the current one.
     */
    protected function term(Request $request): ?Term
    {
        $id = $request->string('term')->toString();

        return ($id !== '' ? Term::query()->whereKey($id)->first() : null) ?? Workspace::term();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function termOptions(): array
    {
        return array_values(Term::query()
            ->join('academic_years', 'academic_years.id', '=', 'terms.academic_year_id')
            ->orderByDesc('academic_years.starts_on')
            ->orderByDesc('terms.sequence')
            ->get(['terms.id', 'terms.name', 'academic_years.name as year'])
            ->map(fn (Term $term) => ['value' => $term->id, 'label' => "{$term->name}, {$term->getAttribute('year')}"])
            ->all());
    }

    /**
     * @param  list<string>|null  $only  limit to these classes
     * @return list<array{value: string, label: string}>
     */
    protected function classOptions(bool $activeOnly = true, ?array $only = null): array
    {
        return array_values(SchoolClass::query()
            ->when($activeOnly, fn ($query) => $query->where('active', true))
            ->when($only !== null, fn ($query) => $query->whereKey($only ?? []))
            ->orderBy('level')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SchoolClass $class) => ['value' => $class->id, 'label' => $class->name])
            ->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function subjectOptions(): array
    {
        return array_values(Subject::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Subject $subject) => ['value' => $subject->id, 'label' => "{$subject->name} ({$subject->code})"])
            ->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function staffOptions(): array
    {
        return array_values(Staff::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Staff $staff) => ['value' => $staff->id, 'label' => $staff->name])
            ->all());
    }
}
