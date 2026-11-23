<?php

namespace App\Procurement;

use App\Models\User;
use App\Models\Workspace;
use App\Procurement\Models\Award;
use App\Procurement\Models\EvaluationCriterion;
use App\Procurement\Models\EvaluationScore;
use App\Procurement\Models\Quotation;
use App\Procurement\Models\QuotationLine;
use App\Procurement\Models\Requisition;
use App\Procurement\Models\SourcingEvent;
use App\Procurement\Models\SourcingItem;
use App\Procurement\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sourcing (ADR-0023 §3): one engine for requests for quotation, requests
 * for proposal, tenders and single-source purchases; the method sets the
 * rules (limits, sealing, the number of offers expected).
 *
 * Integrity: evaluation criteria are fixed when the event is issued (set
 * before any offer is seen); quotations are fixed when it closes; a sealed
 * event shows no prices until then.
 */
final class Sourcing
{
    public const LABELS = [
        'direct' => 'Direct purchase',
        'rfq' => 'Request for quotation',
        'rfp' => 'Request for proposal',
        'tender' => 'Competitive tender',
        'single_source' => 'Single source',
        'framework' => 'Framework agreement',
    ];

    /** Methods that expect competing offers (the minimum number applies). */
    private const COMPETITIVE = ['rfq', 'rfp', 'tender'];

    /**
     * @param  array<string, mixed>  $data
     */
    public static function create(Requisition $requisition, array $data, User $by): SourcingEvent
    {
        return DB::transaction(function () use ($requisition, $data, $by) {
            $requisition = Requisition::query()->lockForUpdate()->findOrFail($requisition->id);
            Requisitions::requireBuyable($requisition);

            $method = (string) $data['method'];
            self::checkMethod($method, $requisition->total_base_minor, $data['method_reason'] ?? null);

            $event = SourcingEvent::query()->create([
                'number' => Numbers::next('sourcing'),
                'requisition_id' => $requisition->id,
                'method' => $method,
                'title' => $data['title'] ?? $requisition->title,
                'description' => $data['description'] ?? null,
                'delivery_location' => $data['delivery_location'] ?? $requisition->getAttribute('delivery_location'),
                'quotes_due_at' => $data['quotes_due_at'],
                'required_by' => $data['required_by'] ?? $requisition->getAttribute('needed_by'),
                'sealed' => in_array($method, ['rfp', 'tender'], true) ? true : (bool) ($data['sealed'] ?? false),
                'method_reason' => $data['method_reason'] ?? null,
                'status' => 'draft',
                'created_by' => $by->id,
            ]);

            foreach ($requisition->lines as $line) {
                SourcingItem::query()->create([
                    'sourcing_event_id' => $event->id,
                    'requisition_line_id' => $line->id,
                    'line_no' => $line->line_no,
                    'kind' => $line->kind,
                    'description' => $line->description,
                    'specification' => $line->specification,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                ]);
            }

            AuditTrail::record('sourcing.created', $event, $by, ['method' => self::LABELS[$method], 'requisition' => $requisition->number]);

            return $event;
        });
    }

    /**
     * Refuses a method above its limit (settings), and a single source
     * without a reason.
     */
    public static function checkMethod(string $method, int $baseMinor, ?string $reason): void
    {
        $limit = Workspace::settings()->methodLimit($method);
        if ($limit !== null && $baseMinor > $limit) {
            throw ValidationException::withMessages(['method' => self::LABELS[$method].' is limited to '.Money::format($limit, Money::base()).' here. Use a more competitive method.']);
        }
        if ($method === 'single_source' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['method_reason' => 'Explain why only one supplier can provide this.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function update(SourcingEvent $event, array $data, User $by): void
    {
        self::requireStatus($event, ['draft'], 'Only a draft is changed.');

        DB::transaction(function () use ($event, $data, $by) {
            $event->fill([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'delivery_location' => $data['delivery_location'] ?? null,
                'quotes_due_at' => $data['quotes_due_at'],
                'required_by' => $data['required_by'] ?? null,
            ]);
            if (! in_array($event->method, ['rfp', 'tender'], true)) {
                $event->sealed = (bool) ($data['sealed'] ?? $event->sealed);
            }
            $event->save();

            foreach ((array) ($data['specifications'] ?? []) as $itemId => $specification) {
                SourcingItem::query()->where('sourcing_event_id', $event->id)->whereKey($itemId)->update(['specification' => $specification]);
            }
            AuditTrail::record('sourcing.updated', $event, $by);
        });
    }

    /**
     * @param  list<string>  $supplierIds
     */
    public static function invite(SourcingEvent $event, array $supplierIds, User $by): void
    {
        self::requireStatus($event, ['draft', 'issued'], 'Suppliers are invited before the event closes.');

        DB::transaction(function () use ($event, $supplierIds, $by) {
            $names = [];
            foreach ($supplierIds as $id) {
                $supplier = Suppliers::active($id, 'supplier_ids');
                if (! $event->invited()->whereKey($supplier->id)->exists()) {
                    $event->invited()->attach($supplier->id, ['id' => (string) str()->uuid7(), 'organization_id' => $event->organization_id]);
                    $names[] = $supplier->name;
                }
            }
            if ($event->method === 'single_source' && $event->invited()->count() > 1) {
                throw ValidationException::withMessages(['supplier_ids' => 'A single-source purchase invites one supplier.']);
            }
            if ($names !== []) {
                AuditTrail::record('sourcing.invited', $event, $by, ['suppliers' => implode(', ', $names)]);
            }
        });
    }

    public static function uninvite(SourcingEvent $event, Supplier $supplier, User $by): void
    {
        self::requireStatus($event, ['draft'], 'Invitations are withdrawn only before the event is issued.');

        DB::transaction(function () use ($event, $supplier, $by) {
            $event->invited()->detach($supplier->id);
            AuditTrail::record('sourcing.uninvited', $event, $by, ['supplier' => $supplier->name]);
        });
    }

    /**
     * Replaces the evaluation criteria. Draft only: they are set before
     * any offer is seen.
     *
     * @param  list<array{name: string, kind: string, mandatory?: bool, max_score?: int|null}>  $criteria
     */
    public static function setCriteria(SourcingEvent $event, array $criteria, User $by): void
    {
        self::requireStatus($event, ['draft'], 'Criteria are fixed once the event is issued.');

        DB::transaction(function () use ($event, $criteria, $by) {
            $event->criteria()->delete();
            foreach ($criteria as $i => $criterion) {
                $scored = $criterion['kind'] === 'score';
                EvaluationCriterion::query()->create([
                    'sourcing_event_id' => $event->id,
                    'name' => $criterion['name'],
                    'kind' => $scored ? 'score' : 'pass_fail',
                    'mandatory' => ! $scored && (bool) ($criterion['mandatory'] ?? false),
                    'max_score' => $scored ? max(1, (int) ($criterion['max_score'] ?? 10)) : null,
                    'sort' => $i,
                ]);
            }
            AuditTrail::record('sourcing.criteria_set', $event, $by, ['criteria' => count($criteria)]);
        });
    }

    public static function issue(SourcingEvent $event, User $by): void
    {
        DB::transaction(function () use ($event, $by) {
            $event = SourcingEvent::query()->lockForUpdate()->findOrFail($event->id);
            self::requireStatus($event, ['draft'], 'Only a draft is issued.');
            if (! $event->invited()->exists()) {
                throw ValidationException::withMessages(['supplier_ids' => 'Invite at least one supplier.']);
            }
            if ($event->quotes_due_at->isPast()) {
                throw ValidationException::withMessages(['quotes_due_at' => 'Set a deadline in the future.']);
            }

            $needsApproval = Approvals::start('sourcing', $event, $event->requisition->total_base_minor, $event->requisition->department_id, $event->method, $by);
            $event->forceFill($needsApproval ? ['status' => 'pending_approval'] : ['status' => 'issued', 'issued_at' => now()])->save();
            AuditTrail::record($needsApproval ? 'sourcing.submitted' : 'sourcing.issued', $event, $by);
        });
    }

    public static function decide(SourcingEvent $event, string $decision, ?string $note, User $by): void
    {
        DB::transaction(function () use ($event, $decision, $note, $by) {
            $event = SourcingEvent::query()->lockForUpdate()->findOrFail($event->id);
            self::requireStatus($event, ['pending_approval'], 'This event is not waiting for approval.');

            $outcome = Approvals::decide('sourcing', $event, $by, $decision, $note);
            match ($outcome) {
                'complete' => $event->forceFill(['status' => 'issued', 'issued_at' => now()])->save(),
                'rejected', 'returned' => $event->forceFill(['status' => 'draft', 'status_reason' => $note])->save(),
                default => null,
            };
            AuditTrail::record($outcome === 'complete' ? 'sourcing.issued' : 'sourcing.'.$outcome, $event, $by, ['note' => $note]);
        });
    }

    /**
     * Records (or, while the event is open, replaces) a supplier's offer.
     *
     * @param  array<string, mixed>  $data
     */
    public static function recordQuotation(SourcingEvent $event, array $data, User $by): Quotation
    {
        return DB::transaction(function () use ($event, $data, $by) {
            $event = SourcingEvent::query()->lockForUpdate()->findOrFail($event->id);
            self::requireStatus($event, ['issued'], 'Quotations are recorded while the event is open. Closed quotations stay as they are.');

            $supplier = Suppliers::active((string) $data['supplier_id']);
            if (! $event->invited()->whereKey($supplier->id)->exists()) {
                if ($event->method !== 'tender') {
                    throw ValidationException::withMessages(['supplier_id' => "{$supplier->name} was not invited to this event."]);
                }
                // An open tender takes offers from any active supplier.
                $event->invited()->attach($supplier->id, ['id' => (string) str()->uuid7(), 'organization_id' => $event->organization_id]);
            }

            $currency = strtoupper((string) $data['currency']);
            $rate = Money::rate($currency);
            $items = $event->items()->get()->keyBy('id');
            $lines = collect((array) $data['lines'])->keyBy('sourcing_item_id');
            if ($lines->keys()->diff($items->keys())->isNotEmpty() || $lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Price the items of this event.']);
            }

            $quotation = Quotation::query()->where('sourcing_event_id', $event->id)->where('supplier_id', $supplier->id)->first()
                ?? new Quotation(['sourcing_event_id' => $event->id, 'supplier_id' => $supplier->id, 'created_by' => $by->id]);
            $replacing = $quotation->exists;

            $quotation->fill([
                'reference' => $data['reference'] ?? null,
                'quoted_on' => $data['quoted_on'],
                'received_at' => now(),
                'late' => now()->greaterThan($event->quotes_due_at),
                'currency' => $currency,
                'rate_minor' => $rate,
                'subtotal_minor' => 0,
                'discount_minor' => Money::parse($data['discount'] ?? '0', $currency, 'discount'),
                'tax_minor' => Money::parse($data['tax'] ?? '0', $currency, 'tax'),
                'delivery_minor' => Money::parse($data['delivery'] ?? '0', $currency, 'delivery'),
                'total_minor' => 0,
                'total_base_minor' => 0,
                'delivery_days' => $data['delivery_days'] ?? null,
                'warranty' => $data['warranty'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'status' => 'received',
            ])->save();

            $quotation->lines()->delete();
            $subtotal = 0;
            foreach ($lines as $itemId => $line) {
                /** @var SourcingItem $item */
                $item = $items[$itemId];
                $price = Money::parse($line['unit_price'], $currency, "lines.{$itemId}.unit_price");
                $amount = Money::lineAmount($item->quantity, $price);
                $subtotal += $amount;
                QuotationLine::query()->create([
                    'quotation_id' => $quotation->id,
                    'sourcing_item_id' => $item->id,
                    'quantity' => $item->quantity,
                    'unit_price_minor' => $price,
                    'amount_minor' => $amount,
                    'compliant' => (bool) ($line['compliant'] ?? true),
                    'note' => $line['note'] ?? null,
                ]);
            }

            $total = $subtotal - $quotation->discount_minor + $quotation->tax_minor + $quotation->delivery_minor;
            if ($total < 0) {
                throw ValidationException::withMessages(['discount' => 'The discount is larger than the quotation.']);
            }
            $quotation->forceFill(['subtotal_minor' => $subtotal, 'total_minor' => $total, 'total_base_minor' => Money::toBase($total, $currency, $rate)])->save();

            AuditTrail::record($replacing ? 'quotation.replaced' : 'quotation.recorded', $quotation->load('supplier', 'event'), $by, [
                'total' => $event->sealed ? 'sealed' : Money::format($total, $currency),
                'late' => $quotation->late ? 'yes' : null,
            ]);

            return $quotation;
        });
    }

    public static function withdrawQuotation(Quotation $quotation, string $reason, User $by): void
    {
        DB::transaction(function () use ($quotation, $reason, $by) {
            self::requireStatus($quotation->event, ['issued'], 'Closed quotations stay as they are.');
            $quotation->forceFill(['status' => 'withdrawn'])->save();
            AuditTrail::record('quotation.withdrawn', $quotation, $by, ['reason' => $reason]);
        });
    }

    public static function close(SourcingEvent $event, User $by): void
    {
        DB::transaction(function () use ($event, $by) {
            $event = SourcingEvent::query()->lockForUpdate()->findOrFail($event->id);
            self::requireStatus($event, ['issued'], 'Only an open event is closed.');
            $event->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            AuditTrail::record('sourcing.closed', $event, $by, ['quotations' => $event->quotations()->where('status', 'received')->count()]);
        });
    }

    public static function startEvaluation(SourcingEvent $event, User $by): void
    {
        DB::transaction(function () use ($event, $by) {
            $event = SourcingEvent::query()->lockForUpdate()->findOrFail($event->id);
            self::requireStatus($event, ['closed'], 'Evaluation opens once the event is closed.');
            if (! $event->quotations()->where('status', 'received')->exists()) {
                throw ValidationException::withMessages(['event' => 'No quotation was received. Cancel the event or issue a new one.']);
            }
            $event->forceFill(['status' => 'evaluation'])->save();
            AuditTrail::record('sourcing.evaluation_started', $event, $by);
        });
    }

    /**
     * One evaluator's results for one quotation.
     *
     * @param  array<string, array{passed?: bool|null, score?: numeric-string|int|float|null, note?: string|null}>  $results  by criterion id
     */
    public static function evaluate(Quotation $quotation, array $results, User $by): void
    {
        $event = $quotation->event;
        self::requireStatus($event, ['evaluation'], 'Quotations are evaluated while the event is in evaluation.');
        if ($quotation->status !== 'received') {
            throw ValidationException::withMessages(['quotation' => 'A withdrawn quotation is not evaluated.']);
        }

        DB::transaction(function () use ($event, $quotation, $results, $by) {
            $criteria = $event->criteria()->get()->keyBy('id');
            foreach ($results as $criterionId => $result) {
                /** @var EvaluationCriterion|null $criterion */
                $criterion = $criteria[$criterionId] ?? null;
                if ($criterion === null) {
                    throw ValidationException::withMessages(['results' => 'Use this event\'s criteria.']);
                }
                $score = $criterion->kind === 'score' ? ($result['score'] ?? null) : null;
                if ($score !== null && ($score < 0 || $score > $criterion->max_score)) {
                    throw ValidationException::withMessages(["results.{$criterionId}.score" => "Score between 0 and {$criterion->max_score}."]);
                }
                EvaluationScore::query()->updateOrCreate(
                    ['quotation_id' => $quotation->id, 'criterion_id' => $criterion->id, 'evaluator_id' => $by->id],
                    [
                        'evaluator_name' => $by->name,
                        'passed' => $criterion->kind === 'pass_fail' ? (bool) ($result['passed'] ?? false) : null,
                        'score' => $score,
                        'note' => $result['note'] ?? null,
                    ],
                );
            }
            AuditTrail::record('quotation.evaluated', $quotation, $by);
        });
    }

    /**
     * The comparison: every received quotation in the workspace currency,
     * with its compliance, scores and rank among the compliant ones.
     *
     * Compliant: every line marked compliant and, on each mandatory
     * criterion, more evaluators passed it than failed it. A mandatory
     * criterion nobody has evaluated yet leaves it "not evaluated".
     *
     * @return list<array<string, mixed>>
     */
    public static function comparison(SourcingEvent $event): array
    {
        $criteria = $event->criteria()->get();
        $quotations = $event->quotations()->with(['supplier', 'lines', 'scores'])->where('status', 'received')->get();

        $rows = $quotations->map(function (Quotation $q) use ($criteria) {
            $compliance = $q->lines->every(fn (QuotationLine $l) => $l->compliant) ? 'compliant' : 'non_compliant';
            $scores = [];
            foreach ($criteria as $criterion) {
                $given = $q->scores->where('criterion_id', $criterion->id);
                if ($criterion->kind === 'pass_fail') {
                    $passes = $given->where('passed', true)->count();
                    $fails = $given->where('passed', false)->count();
                    $outcome = $given->isEmpty() ? null : $passes > $fails;
                    $scores[$criterion->id] = ['passed' => $outcome, 'evaluations' => $given->count()];
                    if ($criterion->mandatory && $compliance === 'compliant') {
                        $compliance = $outcome === null ? 'not_evaluated' : ($outcome ? 'compliant' : 'non_compliant');
                    }
                } else {
                    $scores[$criterion->id] = ['score' => $given->isEmpty() ? null : round((float) $given->avg('score'), 2), 'evaluations' => $given->count()];
                }
            }

            return [
                'quotation' => $q,
                'compliance' => $compliance,
                'scores' => $scores,
                'technical' => round(array_sum(array_map(fn ($s) => $s['score'] ?? 0, $scores)), 2),
            ];
        })->all();

        $compliant = collect($rows)->where('compliance', 'compliant')->sortBy(fn ($r) => $r['quotation']->total_base_minor)->values();
        foreach ($rows as &$row) {
            $position = $compliant->search(fn ($r) => $r['quotation']->id === $row['quotation']->id);
            $row['rank'] = $position === false ? null : $position + 1;
        }

        return array_values($rows);
    }

    /**
     * Recommends a supplier for approval. The lowest compliant offer is the
     * expected choice; anything else, or fewer offers than the minimum on a
     * competitive method, needs a written reason.
     */
    public static function recommend(SourcingEvent $event, Quotation $quotation, string $justification, ?string $deviation, User $by): Award
    {
        return DB::transaction(function () use ($event, $quotation, $justification, $deviation, $by) {
            $event = SourcingEvent::query()->lockForUpdate()->findOrFail($event->id);
            self::requireStatus($event, ['evaluation'], 'An award is recommended once quotations are evaluated.');
            if ($quotation->sourcing_event_id !== $event->id || $quotation->status !== 'received') {
                throw ValidationException::withMessages(['quotation_id' => 'Choose a quotation received on this event.']);
            }
            if ($event->awards()->whereIn('status', ['pending_approval', 'approved'])->exists()) {
                throw ValidationException::withMessages(['quotation_id' => 'An award is already recommended. Wait for its decision or withdraw it.']);
            }
            $quotation->loadMissing('supplier');
            if (! $quotation->supplier->isActive()) {
                throw ValidationException::withMessages(['quotation_id' => "{$quotation->supplier->name} is {$quotation->supplier->status}."]);
            }

            $rows = collect(self::comparison($event));
            $row = $rows->first(fn ($r) => $r['quotation']->id === $quotation->id);
            if ($row['compliance'] !== 'compliant') {
                throw ValidationException::withMessages(['quotation_id' => $row['compliance'] === 'not_evaluated'
                    ? 'Evaluate this quotation against the mandatory criteria first.'
                    : 'This quotation does not meet the requirements; it cannot be awarded.']);
            }

            $needs = [];
            if ($row['rank'] !== 1) {
                $needs[] = 'it is not the lowest compliant offer';
            }
            $received = $rows->count();
            $minimum = Workspace::settings()->min_quotations;
            if (in_array($event->method, self::COMPETITIVE, true) && $received < $minimum) {
                $needs[] = "only {$received} of the {$minimum} quotations expected were received";
            }
            if ($needs !== [] && trim((string) $deviation) === '') {
                throw ValidationException::withMessages(['deviation_reason' => 'Explain the choice: '.implode(', and ', $needs).'.']);
            }

            $award = Award::query()->create([
                'sourcing_event_id' => $event->id,
                'quotation_id' => $quotation->id,
                'supplier_id' => $quotation->supplier_id,
                'recommended_by' => $by->id,
                'justification' => $justification,
                'deviation_reason' => $needs === [] ? null : $deviation,
                'status' => 'pending_approval',
            ]);
            $award->setRelation('event', $event)->setRelation('supplier', $quotation->supplier);

            if (! Approvals::start('award', $award, $quotation->total_base_minor, $event->requisition->department_id, $event->method, $by)) {
                self::approveAward($award, $event);
            }
            AuditTrail::record('award.recommended', $award, $by, ['total' => Money::format($quotation->total_minor, $quotation->currency), 'deviation' => $award->deviation_reason]);

            return $award;
        });
    }

    public static function decideAward(Award $award, string $decision, ?string $note, User $by): void
    {
        DB::transaction(function () use ($award, $decision, $note, $by) {
            $award = Award::query()->lockForUpdate()->with(['event', 'supplier'])->findOrFail($award->id);
            if ($award->status !== 'pending_approval') {
                throw ValidationException::withMessages(['decision' => 'This award is not waiting for approval.']);
            }

            $outcome = Approvals::decide('award', $award, $by, $decision, $note);
            if ($outcome === 'complete') {
                self::approveAward($award, $award->event);
            } elseif ($outcome !== 'pending') {
                // Returned or rejected: the recommendation is closed; evaluation goes on.
                $award->forceFill(['status' => 'rejected', 'decided_at' => now()])->save();
            }
            AuditTrail::record('award.'.match ($outcome) {
                'complete' => 'approved',
                'pending' => 'step_approved',
                default => $outcome,
            }, $award, $by, ['note' => $note]);
        });
    }

    public static function withdrawAward(Award $award, User $by): void
    {
        DB::transaction(function () use ($award, $by) {
            if ($award->status !== 'pending_approval') {
                throw ValidationException::withMessages(['award' => 'Only a recommendation waiting for approval is withdrawn.']);
            }
            Approvals::withdraw('award', $award);
            $award->forceFill(['status' => 'withdrawn'])->save();
            AuditTrail::record('award.withdrawn', $award, $by);
        });
    }

    public static function cancel(SourcingEvent $event, string $reason, User $by): void
    {
        DB::transaction(function () use ($event, $reason, $by) {
            $event = SourcingEvent::query()->lockForUpdate()->findOrFail($event->id);
            self::requireStatus($event, ['draft', 'pending_approval', 'issued', 'closed', 'evaluation'], 'An awarded or cancelled event stays as it is.');

            Approvals::withdraw('sourcing', $event);
            foreach ($event->awards()->where('status', 'pending_approval')->get() as $award) {
                Approvals::withdraw('award', $award);
                $award->forceFill(['status' => 'withdrawn'])->save();
            }
            $event->forceFill(['status' => 'cancelled', 'status_reason' => $reason])->save();
            AuditTrail::record('sourcing.cancelled', $event, $by, ['reason' => $reason]);
        });
    }

    private static function approveAward(Award $award, SourcingEvent $event): void
    {
        $award->forceFill(['status' => 'approved', 'decided_at' => now()])->save();
        $event->forceFill(['status' => 'awarded'])->save();
    }

    /**
     * @param  list<string>  $statuses
     */
    private static function requireStatus(SourcingEvent $event, array $statuses, string $message): void
    {
        if (! in_array($event->status, $statuses, true)) {
            throw ValidationException::withMessages(['event' => $message]);
        }
    }
}
