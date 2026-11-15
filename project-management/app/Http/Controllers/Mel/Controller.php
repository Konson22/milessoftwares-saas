<?php

namespace App\Http\Controllers\Mel;

use App\Collect\Scope;
use App\Mel\Access;
use App\Mel\Models\Programme;
use App\Mel\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

abstract class Controller
{
    protected function user(): User
    {
        $user = request()->user();
        assert($user instanceof User);

        return $user;
    }

    protected function access(): Access
    {
        return app(Access::class);
    }

    /**
     * What the member reaches in data collection.
     */
    protected function scope(): Scope
    {
        return app(Scope::class);
    }

    /**
     * A programme the member can open, or 404.
     */
    protected function programme(string $id): Programme
    {
        return $this->access()->programme($id);
    }

    /**
     * A submission in a programme and unit the member reaches, or 404.
     */
    protected function submission(string $id): Submission
    {
        $submission = Submission::query()->with(['programme', 'indicator.currentVersion', 'version.breakdowns', 'period', 'unit'])->findOrFail($id);
        $programme = $this->programme($submission->programme_id);

        if (! $this->access()->reachesUnit($programme, $submission->unit_id)
            || ! $this->access()->reachesPeriod($programme, $submission->period->starts_on, $submission->period->ends_on)) {
            throw (new ModelNotFoundException)->setModel(Submission::class, [$id]);
        }

        return $submission;
    }

    /**
     * @param  iterable<string>  $values
     * @return list<array{value: string, label: string}>
     */
    protected static function options(iterable $values, ?callable $label = null): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[] = ['value' => $value, 'label' => $label === null ? ucfirst(str_replace('_', ' ', $value)) : $label($value)];
        }

        return $options;
    }
}
