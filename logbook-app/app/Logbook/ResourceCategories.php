<?php

namespace App\Logbook;

use App\Logbook\Models\LogResource;
use App\Logbook\Models\ResourceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asset categories: the broad group an asset belongs to (Vehicles,
 * Generators, Cooling…), above its type (Car, Pickup, Ambulance). Each
 * workspace keeps its own list, starting from the defaults below; managers
 * add, rename, order and switch categories off. Categories are never
 * deleted: assets keep naming theirs.
 *
 * A log sheet's resource field that asks for a type also accepts a
 * category name, so "Vehicles" offers every car, pickup and truck.
 */
final class ResourceCategories
{
    /**
     * The starting list: key, name, what it covers, the types suggested in
     * it, and words that place an existing type in it.
     *
     * @var array<string, array{name: string, description: string, types: list<string>, words: list<string>}>
     */
    public const DEFAULTS = [
        'vehicles' => [
            'name' => 'Vehicles', 'description' => 'Cars, pickups, trucks, motorbikes, ambulances and buses.',
            'types' => ['Car', 'Pickup', 'Truck', 'Motorbike', 'Ambulance', 'Bus', 'Van', 'Tractor', 'Boat'],
            'words' => ['vehicle', 'car', 'pickup', 'pick-up', 'truck', 'lorry', 'motorbike', 'motorcycle', 'boda', 'ambulance', 'bus', 'van', 'tractor', 'cruiser', 'hilux', 'jeep', 'suv', 'boat', 'tuk'],
        ],
        'generators' => [
            'name' => 'Generators', 'description' => 'Diesel and petrol generators.',
            'types' => ['Diesel generator', 'Petrol generator', 'Generator'],
            'words' => ['generator', 'genset', 'gen set'],
        ],
        'power' => [
            'name' => 'Power and solar', 'description' => 'Solar systems, inverters, batteries and UPS units.',
            'types' => ['Solar system', 'Inverter', 'Battery bank', 'UPS'],
            'words' => ['solar', 'inverter', 'battery', 'ups', 'power'],
        ],
        'cooling' => [
            'name' => 'Cooling and cold chain', 'description' => 'Refrigerators, freezers, air conditioners and cold rooms.',
            'types' => ['Refrigerator', 'Freezer', 'Vaccine fridge', 'Air conditioner', 'Cold storage room'],
            'words' => ['fridge', 'refrigerator', 'freezer', 'cold', 'air condition', 'aircon', 'cooler', 'cooling', 'chiller'],
        ],
        'water' => [
            'name' => 'Water', 'description' => 'Pumps, tanks, boreholes and treatment units.',
            'types' => ['Water pump', 'Water tank', 'Borehole', 'Water treatment'],
            'words' => ['water', 'pump', 'tank', 'borehole', 'well'],
        ],
        'it' => [
            'name' => 'IT equipment', 'description' => 'Computers, servers, printers and network equipment.',
            'types' => ['Laptop', 'Desktop computer', 'Server', 'Printer', 'Router'],
            'words' => ['computer', 'laptop', 'server', 'printer', 'router', 'network', 'it'],
        ],
        'medical' => [
            'name' => 'Medical equipment', 'description' => 'Oxygen concentrators, sterilisers and diagnostic equipment.',
            'types' => ['Oxygen concentrator', 'Steriliser', 'Ultrasound', 'X-ray'],
            'words' => ['medical', 'oxygen', 'concentrator', 'steril', 'ultrasound', 'x-ray', 'xray', 'microscope'],
        ],
        'buildings' => [
            'name' => 'Buildings and rooms', 'description' => 'Stores, warehouses, offices and other places that are checked.',
            'types' => ['Store', 'Warehouse', 'Office', 'Room'],
            'words' => ['building', 'room', 'store', 'warehouse', 'office', 'hall'],
        ],
        'other' => [
            'name' => 'Other equipment', 'description' => 'Anything that fits no other category.',
            'types' => ['Equipment', 'Tool', 'Machine'],
            'words' => [],
        ],
    ];

    public const NAME_MAX = 60;

    /**
     * The workspace's categories, the defaults written first when it has
     * none yet.
     *
     * @return Collection<int, ResourceCategory>
     */
    public static function all(bool $withInactive = true): Collection
    {
        self::ensure();

        return ResourceCategory::query()->when(! $withInactive, fn ($q) => $q->where('active', true))
            ->orderBy('sort')->orderBy('name')->get();
    }

    /** Writes the default categories for the current workspace if it has none. */
    public static function ensure(): void
    {
        if (ResourceCategory::query()->exists()) {
            return;
        }
        DB::transaction(function () {
            $sort = 0;
            foreach (self::DEFAULTS as $key => $default) {
                ResourceCategory::query()->create([
                    'key' => $key, 'name' => $default['name'], 'description' => $default['description'],
                    'types' => $default['types'], 'sort' => $sort += 10, 'active' => true,
                ]);
            }
        });
    }

    /** The default category key a type falls in ("other" when no word matches). */
    public static function guessKey(?string $type): string
    {
        $text = mb_strtolower(trim((string) $type));
        foreach (self::DEFAULTS as $key => $default) {
            foreach ($default['words'] as $word) {
                // A word starts the match; short words (IT, UPS, car) must stand alone, plural allowed.
                $end = mb_strlen($word) <= 3 ? 's?(?![a-z])' : '';
                if (preg_match('/(?<![a-z])'.preg_quote($word, '/').$end.'/u', $text) === 1) {
                    return $key;
                }
            }
        }

        return 'other';
    }

    /** The workspace's category for a type: the default it falls in, else Other, else the first. */
    public static function guess(?string $type): ?ResourceCategory
    {
        self::ensure();
        $categories = ResourceCategory::query()->orderBy('sort')->get();
        // A type that is exactly a category's suggested type or name wins.
        $lower = mb_strtolower(trim((string) $type));
        $exact = $categories->first(fn (ResourceCategory $c) => mb_strtolower($c->name) === $lower
            || in_array($lower, array_map('mb_strtolower', $c->types ?? []), true));
        if ($exact !== null) {
            return $exact;
        }

        return $categories->firstWhere('key', self::guessKey($type))
            ?? $categories->firstWhere('key', 'other')
            ?? $categories->first();
    }

    /**
     * Assets a resource field offers: its type, or every asset of the
     * category with that name.
     *
     * @param  Builder<LogResource>  $query
     * @return Builder<LogResource>
     */
    public static function matching(Builder $query, ?string $wanted): Builder
    {
        if ($wanted === null || trim($wanted) === '') {
            return $query;
        }
        $lower = mb_strtolower(trim($wanted));

        return $query->where(fn (Builder $q) => $q->whereRaw('lower(type) = ?', [$lower])
            ->orWhereIn('category_id', ResourceCategory::query()->whereRaw('lower(name) = ?', [$lower])->select('id')));
    }

    /**
     * The report filter: a category id, or (older links) a type or category name.
     *
     * @param  Builder<LogResource>  $query
     * @return Builder<LogResource>
     */
    public static function filter(Builder $query, string $category): Builder
    {
        return Str::isUuid($category) ? $query->where('category_id', $category) : self::matching($query, $category);
    }

    /** The same test for one asset already read. */
    public static function matches(LogResource $resource, ?string $wanted): bool
    {
        if ($wanted === null || trim($wanted) === '') {
            return true;
        }
        $lower = mb_strtolower(trim($wanted));

        return mb_strtolower($resource->type) === $lower
            || ($resource->category !== null && mb_strtolower($resource->category->name) === $lower);
    }

    /**
     * Adds or changes a category.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(?ResourceCategory $category, array $data, User $by): ResourceCategory
    {
        $name = trim((string) ($data['name'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'Give the category a name (up to '.self::NAME_MAX.' characters).';
        } elseif (ResourceCategory::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists()) {
            $errors['name'] = 'Another category has this name.';
        }
        $description = is_string($data['description'] ?? null) && trim($data['description']) !== '' ? mb_substr(trim($data['description']), 0, 200) : null;
        $types = array_values(array_unique(array_filter(array_map(
            fn ($t) => is_string($t) ? mb_substr(trim($t), 0, 48) : '',
            is_array($data['types'] ?? null) ? $data['types'] : (preg_split('/\s*,\s*/', (string) ($data['types'] ?? '')) ?: []),
        ))));
        if (count($types) > 30) {
            $errors['types'] = 'At most 30 types.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($category, $name, $description, $types, $data, $by) {
            $values = ['name' => $name, 'description' => $description, 'types' => $types];
            if (array_key_exists('active', $data)) {
                $values['active'] = (bool) $data['active'];
            }
            if ($category === null) {
                $category = ResourceCategory::query()->create([
                    ...$values, 'sort' => (int) ResourceCategory::query()->max('sort') + 10,
                ]);
                AuditTrail::record('resource_category.created', $category, $by, ['name' => $name]);
            } else {
                $before = $category->only(['name', 'active']);
                $category->update($values);
                if ($category->wasChanged()) {
                    AuditTrail::record('resource_category.updated', $category, $by, ['from' => $before, 'to' => $category->only(['name', 'active'])]);
                }
            }

            return $category;
        });
    }

    /**
     * Options for forms and filters.
     *
     * @return list<array{value: string, label: string, active: bool, types: list<string>, key: string|null}>
     */
    public static function options(bool $withInactive = true): array
    {
        return self::all($withInactive)->map(fn (ResourceCategory $c) => [
            'value' => $c->id, 'label' => $c->name, 'active' => $c->active, 'types' => $c->types ?? [], 'key' => $c->key,
        ])->values()->all();
    }
}
