<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\Promotion;
use App\Support\Industry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Promotions of the industry being served. On a day, at a branch, an item
 * gets the biggest percentage of the promotions running for it there (its
 * own, or its category's). The till gives it by itself, whatever the
 * seller may discount; a seller may still give more, within their limit
 * (Sell::price).
 *
 * The running promotions are read once per instance and day: price a
 * whole catalog with one.
 */
class Promotions
{
    /** @var array<string, Collection<int, Promotion>> by branch and day */
    private array $running = [];

    public function __construct(private readonly Industry $industry) {}

    /**
     * The promotion an item sells under here today, if any.
     */
    public function best(Item $item, string $branchId, ?CarbonImmutable $today = null): ?Promotion
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        return $this->running($branchId, $today)
            ->filter(fn (Promotion $promotion) => $promotion->item_id === $item->id
                || ($promotion->category !== null && $promotion->category === $item->category))
            ->sortByDesc('percent_off')
            ->first();
    }

    /**
     * @throws ValidationException
     */
    public function create(
        User $by,
        string $name,
        int $percentOff,
        ?Item $item,
        ?string $category,
        ?string $branchId,
        CarbonImmutable $startsOn,
        ?CarbonImmutable $endsOn,
    ): Promotion {
        if (($item === null) === ($category === null)) {
            throw ValidationException::withMessages(['item_id' => 'Choose a product, or a category.']);
        }
        if ($item !== null && ! $this->industry->handles($item->kind)) {
            throw ValidationException::withMessages(['item_id' => 'This item is not sold here.']);
        }
        if ($percentOff < 1 || $percentOff > 100) {
            throw ValidationException::withMessages(['percent_off' => 'A promotion takes 1 to 100% off.']);
        }
        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'It ends on or after the day it starts.']);
        }

        return Promotion::query()->create([
            'application' => $this->industry->code(),
            'name' => $name,
            'percent_off' => $percentOff,
            'item_id' => $item?->id,
            'category' => $category,
            'branch_id' => $branchId,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'created_by' => $by->id,
        ]);
    }

    /**
     * Stops it now; sales made under it keep it.
     */
    public function end(Promotion $promotion): void
    {
        $promotion->forceFill(['active' => false])->save();
    }

    /**
     * @return Collection<int, Promotion>
     */
    private function running(string $branchId, CarbonImmutable $today): Collection
    {
        return $this->running[$branchId.':'.$today->toDateString()] ??= Promotion::query()
            ->where('application', $this->industry->code())
            ->where('active', true)
            ->whereDate('starts_on', '<=', $today)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->get();
    }
}
