<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Models\BranchCounterSettings;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * When a branch needs to know who it sells to. By default nobody has to be
 * named: a walk-in sale is never held up. A branch may ask for the customer
 * on every sale, on sales of prescription-only medicines, or above an
 * amount; a name is enough, a registered customer also counts.
 */
class CustomerRequirement
{
    /**
     * @throws ValidationException
     */
    public function ensure(BranchCounterSettings $settings, bool $named, bool $restricted, int $totalMinor, string $field = 'customer_name'): void
    {
        if ($named) {
            return;
        }

        $reason = match (true) {
            $settings->require_customer_always => 'This branch records the customer on every sale.',
            $settings->require_customer_for_restricted && $restricted => 'This branch records the customer when prescription-only medicines are sold.',
            $settings->require_customer_above_minor !== null && $totalMinor > $settings->require_customer_above_minor => 'This branch records the customer on sales over '.Money::format($settings->require_customer_above_minor).'.',
            default => null,
        };

        if ($reason !== null) {
            throw ValidationException::withMessages([$field => "{$reason} Enter their name or choose a registered customer."]);
        }
    }
}
