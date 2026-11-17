<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Enums\GrantStatus;
use App\Models\Grant;
use App\Models\GrantDisbursement;
use App\Models\Organization;
use App\Models\Partnership;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A grant agreement between a recipient and a donor connected on Tatook
 * (Phase 5). The grant stays the recipient's record, in its organization;
 * both parties authorize it:
 *
 *   recipient proposes the terms -> donor approves or declines
 *
 * Once approved, the amount approved is a ceiling: the grant is not awarded or
 * amended above it, and payments are not requested past it. A new proposal
 * (a higher amount, say) is approved the same way; the terms before stay in
 * the audit trail.
 *
 * Disbursements, each a scheduled payment of the grant:
 *
 *   planned -> requested (recipient) -> approved | rejected (donor)
 *   approved -> sent (donor records the transfer and its reference)
 *   sent -> received (recipient confirms) | disputed (recipient, with why)
 *   disputed -> sent (donor, corrected)    rejected -> requested (again)
 *
 * "Sent" is the donor's own record of a transfer it made. It is not a
 * Finance confirmation: Finance's integration contract covers a project's
 * own payments (ADR-0017), not grant transfers between organizations. No
 * payment is marked received for the recipient; it confirms it itself.
 * Grants without an agreement work as before.
 */
final class GrantAgreements
{
    public const PROPOSED = 'proposed';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    /** Payments that count against the approved amount. */
    public const COMMITTED = ['requested', 'approved', 'sent', 'received', 'disputed'];

    /**
     * The donor connected to this grant's funder record, if it is on Tatook.
     * Run in the recipient's organization.
     */
    public static function partnership(Grant $grant): ?Partnership
    {
        return Partnership::query()->with('donor')
            ->where('beneficiary_organization_id', $grant->organization_id)
            ->where('funder_id', $grant->funder_id)->whereNotNull('accepted_at')->first();
    }

    /**
     * The recipient proposes the grant's terms to its connected donor.
     *
     * @throws ValidationException
     */
    public static function propose(Grant $grant, ?string $note, User $by): void
    {
        DB::transaction(function () use ($grant, $note, $by) {
            $grant = Grant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
            $partnership = self::partnership($grant)
                ?? throw ValidationException::withMessages(['agreement' => 'Only a grant from a donor connected on Tatook can be agreed here.']);
            if (in_array($grant->status, [GrantStatus::Closed, GrantStatus::Declined], true)) {
                throw ValidationException::withMessages(['agreement' => 'This grant is closed or declined.']);
            }
            if ($grant->agreement_status === self::PROPOSED) {
                throw ValidationException::withMessages(['agreement' => 'These terms are waiting for the donor already.']);
            }
            if ($grant->amount_minor <= 0) {
                throw ValidationException::withMessages(['agreement' => 'Give the grant an amount first.']);
            }
            if ($grant->agreement_amount_minor !== null && $grant->amount_minor < self::committedMinor($grant)) {
                throw ValidationException::withMessages(['agreement' => 'The amount cannot be less than the payments already requested.']);
            }

            $grant->forceFill([
                'agreement_status' => self::PROPOSED,
                'agreement_end_on' => $grant->end_on,
                'agreement_proposed_at' => now(),
                'agreement_proposed_by' => $by->id,
                'agreement_note' => self::text($note),
            ])->save();
            self::audit('grant.agreement_proposed', $grant, $partnership, ['amount_minor' => $grant->amount_minor, 'currency' => $grant->currency, 'end_on' => $grant->end_on?->toDateString()], $by);
            self::tellDonor($partnership, $grant, "{$partnership->name} proposes grant {$grant->reference}: ".Money::format($grant->amount_minor, $grant->currency), $by);
        });
    }

    /**
     * The donor approves or declines the terms proposed. Run with $donor the
     * acting organization; the grant is read and written in the recipient's.
     *
     * @throws ValidationException
     */
    public static function decide(Partnership $partnership, string $grantId, bool $approve, ?string $note, User $by): void
    {
        self::inRecipient($partnership, function () use ($partnership, $grantId, $approve, $note, $by) {
            $grant = Grant::query()->where('funder_id', $partnership->funder_id)->whereKey($grantId)->lockForUpdate()->firstOrFail();
            if ($grant->agreement_status !== self::PROPOSED) {
                throw ValidationException::withMessages(['agreement' => 'Nothing is waiting for your decision on this grant.']);
            }
            if (! $approve && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => 'Say why, so they can propose again.']);
            }

            $grant->forceFill([
                'agreement_status' => $approve ? self::APPROVED : self::DECLINED,
                'agreement_amount_minor' => $approve ? $grant->amount_minor : $grant->agreement_amount_minor,
                'agreement_decided_at' => now(),
                'agreement_decided_by' => $by->id,
                'agreement_note' => self::text($note) ?? $grant->agreement_note,
            ])->save();
            self::audit($approve ? 'grant.agreement_approved' : 'grant.agreement_declined', $grant, $partnership, ['amount_minor' => $grant->amount_minor, 'currency' => $grant->currency, 'note' => self::text($note)], $by);
            self::tellRecipient($partnership, $grant, $approve ? "{$partnership->donor->name} approved grant {$grant->reference}" : "{$partnership->donor->name} declined the terms of grant {$grant->reference}", self::text($note), $by);
        });
    }

    /**
     * The ceiling an agreed grant's amount must keep to (Grants::award, amend).
     *
     * @throws ValidationException
     */
    public static function ensureWithinAgreement(Grant $grant, int $amountMinor): void
    {
        if ($grant->agreement_status === null) {
            return;
        }
        if ($grant->agreement_status === self::PROPOSED) {
            throw ValidationException::withMessages(['amount' => 'The terms are waiting for the donor: change the amount once they decide.']);
        }
        if ($grant->agreement_amount_minor === null || $amountMinor > $grant->agreement_amount_minor) {
            throw ValidationException::withMessages(['amount' => 'The donor approved '.Money::format((int) $grant->agreement_amount_minor, $grant->currency).'. Propose the new amount to them first.']);
        }
    }

    /**
     * The recipient asks for a scheduled payment.
     *
     * @throws ValidationException
     */
    public static function request(GrantDisbursement $disbursement, User $by): void
    {
        DB::transaction(function () use ($disbursement, $by) {
            $grant = Grant::query()->whereKey($disbursement->grant_id)->lockForUpdate()->firstOrFail();
            $disbursement = GrantDisbursement::query()->whereKey($disbursement->id)->lockForUpdate()->firstOrFail();
            $partnership = self::partnership($grant);
            if ($partnership === null || $grant->agreement_status !== self::APPROVED || $grant->status !== GrantStatus::Active) {
                throw ValidationException::withMessages(['disbursement' => 'Payments are requested on an active grant whose terms the donor approved.']);
            }
            if (! in_array($disbursement->status, ['planned', 'rejected'], true)) {
                throw ValidationException::withMessages(['disbursement' => 'This payment has been requested already.']);
            }
            $after = self::committedMinor($grant) + $disbursement->amount_minor;
            if ($after > (int) $grant->agreement_amount_minor) {
                throw ValidationException::withMessages(['disbursement' => 'This would ask for more than the donor approved ('.Money::format((int) $grant->agreement_amount_minor, $grant->currency).').']);
            }

            $disbursement->forceFill(['status' => 'requested', 'requested_at' => now(), 'requested_by' => $by->id, 'decided_at' => null, 'decided_by' => null, 'decision_note' => null])->save();
            self::audit('grant.disbursement_requested', $disbursement, $partnership, ['grant' => $grant->reference, 'amount_minor' => $disbursement->amount_minor, 'due_on' => $disbursement->due_on->toDateString()], $by);
            self::tellDonor($partnership, $grant, "{$partnership->name} requests ".Money::format($disbursement->amount_minor, $grant->currency)." on grant {$grant->reference}", $by);
        });
    }

    /**
     * The donor approves or rejects a requested payment.
     *
     * @throws ValidationException
     */
    public static function decidePayment(Partnership $partnership, string $disbursementId, bool $approve, ?string $note, User $by): void
    {
        self::payment($partnership, $disbursementId, ['requested'], function (Grant $grant, GrantDisbursement $disbursement) use ($partnership, $approve, $note, $by) {
            if (! $approve && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => 'Say why, so they can ask again.']);
            }
            $disbursement->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'decided_by' => $by->id, 'decision_note' => self::text($note)])->save();
            self::audit($approve ? 'grant.disbursement_approved' : 'grant.disbursement_rejected', $disbursement, $partnership, ['grant' => $grant->reference, 'amount_minor' => $disbursement->amount_minor, 'note' => self::text($note)], $by);
            self::tellRecipient($partnership, $grant, $approve ? "{$partnership->donor->name} approved a payment of ".Money::format($disbursement->amount_minor, $grant->currency) : "{$partnership->donor->name} rejected a payment request", self::text($note), $by);
        });
    }

    /**
     * The donor records the transfer it made: when and its reference. Its own
     * record, not Finance's.
     *
     * @throws ValidationException
     */
    public static function markSent(Partnership $partnership, string $disbursementId, CarbonImmutable $on, string $reference, User $by): void
    {
        if ($on->gt(today())) {
            throw ValidationException::withMessages(['sent_on' => 'A transfer cannot be sent in the future.']);
        }
        self::payment($partnership, $disbursementId, ['approved', 'disputed'], function (Grant $grant, GrantDisbursement $disbursement) use ($partnership, $on, $reference, $by) {
            $disbursement->forceFill(['status' => 'sent', 'sent_on' => $on, 'sent_reference' => trim($reference), 'sent_by' => $by->id])->save();
            self::audit('grant.disbursement_sent', $disbursement, $partnership, ['grant' => $grant->reference, 'amount_minor' => $disbursement->amount_minor, 'sent_on' => $on->toDateString(), 'reference' => trim($reference)], $by);
            self::tellRecipient($partnership, $grant, "{$partnership->donor->name} sent ".Money::format($disbursement->amount_minor, $grant->currency)." for grant {$grant->reference}", 'Confirm it once it reaches your account, or say what is wrong.', $by);
        });
    }

    /**
     * The recipient confirms the money arrived (posting it to its books as
     * before, Grants::receive), or disputes what was sent.
     *
     * @throws ValidationException
     */
    public static function confirm(GrantDisbursement $disbursement, bool $received, ?CarbonImmutable $on, ?string $note, User $by): void
    {
        DB::transaction(function () use ($disbursement, $received, $on, $note, $by) {
            $disbursement = GrantDisbursement::query()->whereKey($disbursement->id)->lockForUpdate()->firstOrFail();
            if ($disbursement->status !== 'sent') {
                throw ValidationException::withMessages(['disbursement' => 'Only a payment the donor recorded as sent can be confirmed or disputed.']);
            }
            $grant = Grant::query()->findOrFail($disbursement->grant_id);
            $partnership = self::partnership($grant);

            if ($received) {
                Grants::receive($disbursement, $on ?? today()->toImmutable(), $by);
                $disbursement->forceFill(['received_by' => $by->id])->save();
            } else {
                if (trim((string) $note) === '') {
                    throw ValidationException::withMessages(['note' => 'Say what is wrong: the amount, nothing arrived...']);
                }
                $disbursement->forceFill(['status' => 'disputed', 'dispute_note' => self::text($note)])->save();
            }
            if ($partnership !== null) {
                self::audit($received ? 'grant.disbursement_confirmed' : 'grant.disbursement_disputed', $disbursement, $partnership, ['grant' => $grant->reference, 'amount_minor' => $disbursement->amount_minor, 'note' => self::text($note)], $by);
                self::tellDonor($partnership, $grant, $received ? "{$partnership->name} confirmed receiving ".Money::format($disbursement->amount_minor, $grant->currency) : "{$partnership->name} disputes a payment on grant {$grant->reference}", $by);
            }
        });
    }

    /**
     * Payments requested or further, against the approved amount.
     */
    public static function committedMinor(Grant $grant): int
    {
        return (int) GrantDisbursement::query()->where('grant_id', $grant->id)->whereIn('status', self::COMMITTED)->sum('amount_minor');
    }

    /**
     * @param  list<string>  $from
     * @param  callable(Grant, GrantDisbursement): void  $callback
     */
    private static function payment(Partnership $partnership, string $disbursementId, array $from, callable $callback): void
    {
        self::inRecipient($partnership, function () use ($partnership, $disbursementId, $from, $callback) {
            $disbursement = GrantDisbursement::query()->whereKey($disbursementId)->lockForUpdate()->firstOrFail();
            $grant = Grant::query()->where('funder_id', $partnership->funder_id)->whereKey($disbursement->grant_id)->firstOrFail();
            if (! in_array($disbursement->status, $from, true)) {
                throw ValidationException::withMessages(['disbursement' => 'This payment is not at that step.']);
            }
            $callback($grant, $disbursement);
        });
    }

    /**
     * @param  callable(): void  $callback
     */
    private static function inRecipient(Partnership $partnership, callable $callback): void
    {
        $recipient = $partnership->beneficiary;
        abort_unless($partnership->accepted_at !== null && $recipient instanceof Organization && $recipient->isActive(), 404);
        app(CurrentOrganization::class)->run($recipient, fn () => DB::transaction(fn () => $callback()));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function audit(string $action, Grant|GrantDisbursement $subject, Partnership $partnership, array $properties, User $by): void
    {
        Audit::record($action, $subject, $properties, (string) $partnership->beneficiary_organization_id, $by);
        Audit::record($action, $subject, [...$properties, 'beneficiary' => $partnership->name], $partnership->donor_organization_id, $by);
    }

    private static function tellDonor(Partnership $partnership, Grant $grant, string $title, User $by): void
    {
        if (! $partnership->donor->isActive()) {
            return;
        }
        app(CurrentOrganization::class)->run($partnership->donor, fn () => Alerts::tell(
            Alerts::holders('grants.view'),
            UserNotification::MESSAGE,
            $title,
            null,
            route('beneficiaries.grants.show', ['partnership' => $partnership->id, 'grant' => $grant->id], false),
            $by,
        ));
    }

    private static function tellRecipient(Partnership $partnership, Grant $grant, string $title, ?string $excerpt, User $by): void
    {
        Alerts::tell(Alerts::holders('grants.manage'), UserNotification::MESSAGE, $title, $excerpt, route('grants.show', ['grant' => $grant->id], false), $by);
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
