<?php

namespace App\FinanceIntegration;

use App\Models\Organization;

/**
 * How an organization's spending and payments relate to Finance (ADR-0017
 * §5, Phase 3B). Every organization starts, and stays, in legacy until
 * someone switches it with projects:finance-mode, giving a reason, after the
 * checks pass (Readiness). Nothing switches a mode automatically.
 *
 *   legacy                   Projects records payments; Finance is told (outbox)
 *   reconciliation_required  as legacy, while open items are cleared
 *   ready                    as legacy; the checks passed
 *   integrated               approved spending goes to Finance as a request;
 *                            only Finance's outcomes mark it paid
 *   suspended                no new requests reach Finance; open ones still
 *                            settle from Finance's outcomes; Projects does
 *                            not take payment back over
 */
enum FinanceMode: string
{
    case Legacy = 'legacy';
    case ReconciliationRequired = 'reconciliation_required';
    case Ready = 'ready';
    case Integrated = 'integrated';
    case Suspended = 'suspended';

    /**
     * Switches allowed. Integrated never goes straight back to legacy: it
     * goes through suspended, and only with nothing open in Finance
     * (Readiness).
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Legacy => [self::ReconciliationRequired],
            self::ReconciliationRequired => [self::Ready, self::Legacy],
            self::Ready => [self::Integrated, self::ReconciliationRequired, self::Legacy],
            self::Integrated => [self::Suspended],
            self::Suspended => [self::Integrated, self::Legacy],
        };
    }

    public function allows(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Payment of spending is Finance's: Projects' own pay paths refuse. */
    public function financeOwnsPayment(): bool
    {
        return $this === self::Integrated || $this === self::Suspended;
    }

    public function label(): string
    {
        return match ($this) {
            self::Legacy => 'Legacy (payments recorded in Projects)',
            self::ReconciliationRequired => 'Reconciliation required',
            self::Ready => 'Ready for Finance',
            self::Integrated => 'Integrated (Finance pays)',
            self::Suspended => 'Suspended',
        };
    }

    public static function of(Organization $organization): self
    {
        return self::tryFrom((string) $organization->getAttribute('finance_mode')) ?? self::Legacy;
    }
}
