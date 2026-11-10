<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A list's own name for a step of its work, inside one of the fixed
 * categories (Task::STATUSES). A task's status stays the category.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $list_id
 * @property string $name
 * @property string $category
 * @property int $position
 * @property string $created_by
 * @property-read TaskList $list
 */
class ListStatus extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $table = 'list_statuses';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<TaskList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'list_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'list_status_id');
    }

    /**
     * Where a task of this category lands in this list, if it names any.
     */
    public static function firstFor(string $listId, string $category): ?self
    {
        return static::query()->where('list_id', $listId)->where('category', $category)->orderBy('position')->first();
    }
}
