<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\Branch;

/**
 * The period and branch a management page adds up: ?from=&to= (today
 * without them, at most 92 days) and ?branch= (one of the member's
 * branches, or all of them). Only for those who oversee every branch.
 */
final class ManagementPeriod
{
    public const MAX_DAYS = 92;

    /** @param list<Branch> $open every branch open to the member */
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $chosen,
        public readonly array $open,
    ) {}

    /**
     * @throws ValidationException
     */
    public static function from(Request $request, Branches $branches, ?int $defaultDays = null): self
    {
        abort_unless($branches->overview(), 403, 'Only those who oversee every branch see them together.');

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'uuid'],
        ]);

        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->endOfDay() : CarbonImmutable::today()->endOfDay();
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->startOfDay()
            : $to->subDays(($defaultDays ?? 1) - 1)->startOfDay();
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose at most '.self::MAX_DAYS.' days.']);
        }

        $open = $branches->available();
        $chosen = $validated['branch'] ?? null;
        if ($chosen !== null && ! in_array($chosen, array_map(fn (Branch $branch) => $branch->id, $open), true)) {
            throw ValidationException::withMessages(['branch' => 'Choose one of your branches.']);
        }

        return new self($from, $to, $chosen, $open);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function between(): array
    {
        return [$this->from, $this->to];
    }

    /**
     * The branches added up: the one chosen, or all open to the member.
     *
     * @return list<Branch>
     */
    public function shown(): array
    {
        return array_values(array_filter($this->open, fn (Branch $branch) => $this->chosen === null || $branch->id === $this->chosen));
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(fn (Branch $branch) => $branch->id, $this->shown());
    }

    /** @return array<string, string> */
    public function names(): array
    {
        $names = [];
        foreach ($this->open as $branch) {
            $names[$branch->id] = $branch->name;
        }

        return $names;
    }

    /**
     * For the page: the filters and the branches to choose from.
     *
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [
            'filters' => ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'branch' => $this->chosen],
            'branchOptions' => array_map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name], $this->open),
            'shownBranches' => array_map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name], $this->shown()),
        ];
    }
}
