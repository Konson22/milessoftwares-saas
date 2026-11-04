<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The organization's rules for money leaving its accounts.
 *
 * A payment request always needs approval from the limit up (with no limit,
 * always). Turning payment approval on also holds the ways of paying without
 * a request: direct supplier payments, money out, transfers, tax settlements,
 * bank-crediting manual journals and journal reversals, bank-crediting
 * recurring journals, and outgoing statement lines entered in reconciliation.
 */
class PaymentPolicy
{
    /** How many times above or below the rate on record a typed rate may be. */
    private const RATE_DRIFT = 2;

    public function __construct(private readonly Currencies $currencies) {}

    public function needsApproval(int $booksMinor): bool
    {
        $limit = Workspace::current()?->payment_approval_threshold_minor;

        return $limit === null || $booksMinor >= $limit;
    }

    /**
     * Whether what needs approval is reviewed before it reaches an approver.
     */
    public function reviewsFirst(): bool
    {
        return Workspace::current()->payment_review ?? false;
    }

    /**
     * Whether money leaving a bank, already in the books' currency, must wait.
     */
    public function holdsDirect(int $booksMinor): bool
    {
        return (Workspace::current()->payment_approval ?? false) && $this->needsApproval($booksMinor);
    }

    /**
     * Whether a payment made without a request must become one. Judged like
     * approvalNeeded(), so a typed rate cannot take it below the limit.
     *
     * @throws ValidationException when the typed rate is out of line with the day's
     */
    public function holdsPayment(int $amountMinor, ?string $currency, CarbonImmutable $on, ?string $rate = null): bool
    {
        $needed = $this->approvalNeeded($amountMinor, $currency, $on, $rate);

        return (Workspace::current()->payment_approval ?? false) && $needed;
    }

    /**
     * Whether a payment needs approval. A typed rate only ever raises the
     * amount compared with the limit, and with no rate on record to compare
     * it against, approval is always needed: nothing vouches for it.
     *
     * @throws ValidationException when the typed rate is out of line with the day's
     */
    public function approvalNeeded(int $amountMinor, ?string $currency, CarbonImmutable $on, ?string $rate = null): bool
    {
        if ($currency !== null && $rate !== null && $this->dayRate($currency, $on) === null) {
            return true;
        }

        return $this->needsApproval($this->booksAmount($amountMinor, $currency, $on, $rate));
    }

    /**
     * How much of these stored journal lines leaves a bank or cash account:
     * credits to one, and when the journal reverses itself, debits to one too.
     *
     * @param  list<array{account_id: string, debit_minor: int, credit_minor: int}>  $lines
     * @param  list<string>|null  $bankIds  when already known, for many templates in a row
     */
    public function bankOutflow(array $lines, bool $reverses = false, ?array $bankIds = null): int
    {
        $banks = $bankIds ?? $this->bankIds(array_column($lines, 'account_id'));
        $out = 0;
        foreach ($lines as $line) {
            if (in_array($line['account_id'], $banks, true)) {
                $out += (int) $line['credit_minor'] + ($reverses ? (int) $line['debit_minor'] : 0);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>|null  $among  all bank and cash accounts when null
     * @return list<string>
     */
    public function bankIds(?array $among = null): array
    {
        /** @var list<string> $ids */
        $ids = Account::query()->banks()->when($among !== null, fn ($query) => $query->whereIn('id', $among))->pluck('id')->all();

        return $ids;
    }

    /**
     * Whether one person is kept from acting twice on the same payment.
     */
    public function separated(): bool
    {
        return Workspace::current()->payment_separation ?? true;
    }

    /**
     * An amount in the books' currency, for comparing with the limit and
     * showing to approvers: at the day's rate, or a typed rate if that gives
     * more. A typed rate alone counts only when no rate is on record.
     *
     * @throws ValidationException when there is no rate at all, or the typed one is out of line with the day's
     */
    public function booksAmount(int $amountMinor, ?string $currency, CarbonImmutable $on, ?string $rate = null): int
    {
        if ($currency === null) {
            return $amountMinor;
        }
        if ($rate === null) {
            return Currencies::toBooks($amountMinor, $currency, $this->currencies->rate($currency, $on));
        }

        $typed = Currencies::toBooks($amountMinor, $currency, $rate);
        $day = $this->dayRate($currency, $on);
        if ($day === null) {
            return $typed;
        }

        $this->checkRate($rate, $day, $currency);

        return max($typed, Currencies::toBooks($amountMinor, $currency, $day));
    }

    private function dayRate(string $currency, CarbonImmutable $on): ?string
    {
        try {
            return $this->currencies->rate($currency, $on);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * @throws ValidationException
     */
    private function checkRate(string $rate, string $day, string $currency): void
    {
        if (! is_numeric($rate) || ! is_numeric($day)) {
            throw new InvalidArgumentException("Not a rate: {$rate}");
        }

        $limit = (string) self::RATE_DRIFT;
        if (bccomp($rate, bcmul($day, $limit, 12), 12) > 0 || bccomp(bcmul($rate, $limit, 12), $day, 12) < 0) {
            throw ValidationException::withMessages(['rate' => "That rate is too far from the {$currency} rate on record ({$day}). Correct it, or update the rate under Currencies."]);
        }
    }

    /**
     * @throws ValidationException before the workspace is provisioned
     */
    public function configure(bool $approval, ?int $thresholdMinor, bool $review, bool $separation): Workspace
    {
        $workspace = Workspace::current() ?? throw ValidationException::withMessages(['payment_approval' => 'This organization is not set up yet.']);
        $workspace->forceFill([
            'payment_approval' => $approval,
            'payment_approval_threshold_minor' => $thresholdMinor,
            'payment_review' => $review,
            'payment_separation' => $separation,
        ])->save();

        return $workspace;
    }
}
