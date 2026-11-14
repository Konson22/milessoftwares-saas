<?php

namespace App\Mel;

use App\Models\Programme;
use App\Models\Result;
use App\Models\ResultLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A programme's results framework: its levels (renamed, added or dropped
 * per programme) and the results in them, each under a parent exactly one
 * level up.
 */
class Frameworks
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Programmes $programmes,
    ) {}

    /**
     * Replaces the level names, top first. A level that holds results can
     * be renamed but not dropped.
     *
     * @param  list<string>  $names
     */
    public function setLevels(Programme $programme, array $names, User $user): void
    {
        $this->programmes->assertNotArchived($programme);

        DB::transaction(function () use ($programme, $names, $user) {
            $levels = $programme->levels()->lockForUpdate()->get()->keyBy('depth');

            foreach ($levels as $depth => $level) {
                if ($depth > count($names) && Result::query()->where('level_id', $level->id)->exists()) {
                    throw ValidationException::withMessages(['levels' => "The {$level->name} level holds results: move or archive them first."]);
                }
            }

            foreach ($names as $i => $name) {
                $level = $levels->get($i + 1) ?? new ResultLevel(['programme_id' => $programme->id, 'depth' => $i + 1]);
                $level->fill(['name' => $name])->save();
            }

            $levels->filter(fn (ResultLevel $level) => $level->depth > count($names))->each->delete();
            $this->audit->record('framework.levels_set', $programme, $user, ['levels' => $names]);
        });
    }

    /**
     * @param  array{level_id: string, parent_id?: string|null, code: string, statement: string, description?: string|null, assumptions?: string|null, risks?: string|null, means_of_verification?: string|null, responsible_id?: string|null, linked_milestone_id?: string|null, sort_order?: int}  $data
     */
    public function save(Programme $programme, ?Result $result, array $data, User $user): Result
    {
        $this->programmes->assertNotArchived($programme);

        $level = $programme->levels()->find($data['level_id'])
            ?? throw ValidationException::withMessages(['level_id' => 'Choose a level of this framework.']);
        $parentId = $data['parent_id'] ?? null;

        if ($level->depth === 1 && $parentId !== null) {
            throw ValidationException::withMessages(['parent_id' => "A {$level->name} has no parent."]);
        }

        if ($level->depth > 1) {
            $parent = $parentId === null ? null : $programme->results()->with('level')->find($parentId);
            if ($parent === null || $parent->level->depth !== $level->depth - 1) {
                $above = $programme->levels()->where('depth', $level->depth - 1)->value('name');

                throw ValidationException::withMessages(['parent_id' => "Choose the {$above} this {$level->name} contributes to."]);
            }
            if ($result !== null && $this->isDescendant($parent, $result)) {
                throw ValidationException::withMessages(['parent_id' => 'A result cannot sit under one of its own results.']);
            }
        }

        if ($result !== null && $result->level_id !== $level->id && Result::query()->where('parent_id', $result->id)->exists()) {
            throw ValidationException::withMessages(['level_id' => 'Results sit under this one: move them before changing its level.']);
        }

        return DB::transaction(function () use ($programme, $result, $data, $user) {
            $result ??= new Result(['programme_id' => $programme->id]);
            $created = ! $result->exists;
            $result->fill($data)->save();
            $this->audit->record($created ? 'result.created' : 'result.updated', $result, $user);

            return $result;
        });
    }

    /**
     * Archived results and their indicators stay in history and reports.
     */
    public function archive(Result $result, bool $archived, User $user): void
    {
        DB::transaction(function () use ($result, $archived, $user) {
            $result->update(['archived_at' => $archived ? now() : null]);
            $this->audit->record($archived ? 'result.archived' : 'result.restored', $result, $user);
        });
    }

    private function isDescendant(Result $candidate, Result $ancestor): bool
    {
        $seen = [];
        $node = $candidate;

        while ($node !== null && ! isset($seen[$node->id])) {
            if ($node->id === $ancestor->id) {
                return true;
            }
            $seen[$node->id] = true;
            $node = $node->parent;
        }

        return false;
    }
}
