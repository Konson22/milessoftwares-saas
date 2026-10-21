<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Enums\CounterWorkflow;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\CounterOrder;
use App\Modules\Sales\Models\PrintedDocument;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * What a branch's counter prints, and the log of what it printed.
 *
 * After each step (a sale, an order taken, paid, handed over) the branch's
 * settings say which documents print by themselves. A document prints by
 * itself once: when payment and dispensing both ask for the receipt, the
 * second finds it printed. Printing is claimed before the paper comes out
 * (copy 1, unique per document), so two screens never both print it. Every
 * later print is a numbered reprint and says so. Printing never changes the
 * sale or order, and a sale is complete whether or not anything printed.
 */
class CounterPrinting
{
    public const SOLD = 'sold';

    public const ORDERED = 'ordered';

    public const PAID = 'paid';

    public const HANDED_OVER = 'handed_over';

    /**
     * The documents to print by themselves after a step, not yet printed.
     *
     * @return list<array{document: string, reference: Sale|CounterOrder}>
     */
    public function after(string $step, BranchCounterSettings $settings, Sale|CounterOrder $subject): array
    {
        $order = $subject instanceof CounterOrder ? $subject : null;
        $sale = $subject instanceof Sale ? $subject : $order?->sale;
        $documents = [];

        // The receipt, after payment or after dispensing, whichever the
        // branch wants: it needs the sale, so dispensing before payment
        // prints it once paid.
        $receipt = match ($step) {
            self::SOLD => $settings->print_receipt_after_payment || $settings->print_receipt_after_dispensing,
            self::PAID => $settings->print_receipt_after_payment || ($settings->print_receipt_after_dispensing && $order?->isHandedOver()),
            self::HANDED_OVER => $settings->print_receipt_after_dispensing && $order?->isPaid(),
            default => false,
        };
        if ($receipt && $sale !== null) {
            $documents[] = ['document' => PrintedDocument::RECEIPT, 'reference' => $sale];
        }
        if ($step === self::ORDERED && $order !== null && $settings->print_order_slip) {
            $documents[] = ['document' => PrintedDocument::ORDER_SLIP, 'reference' => $order];
        }
        if ($step === self::PAID && $order?->workflow === CounterWorkflow::OrderPayCollect && $settings->print_collection_slip) {
            $documents[] = ['document' => PrintedDocument::COLLECTION_SLIP, 'reference' => $order];
        }

        return array_values(array_filter($documents, fn (array $print) => $this->copies($print['document'], $print['reference']->getKey()) === 0));
    }

    /**
     * Records a printing. Printing by itself only claims the original:
     * null when it was already printed, so nothing prints twice.
     */
    public function record(string $document, Sale|CounterOrder $reference, User $by, bool $automatic): ?PrintedDocument
    {
        if ($automatic) {
            try {
                return DB::transaction(fn () => $this->create($document, $reference, $by, 1, true));
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        }

        // Reprints number on from the last copy; the unique copy number
        // settles two reprints at once.
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->create($document, $reference, $by, $this->copies($document, $reference->getKey()) + 1, false));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }
    }

    /**
     * How many times a document was printed.
     */
    public function copies(string $document, string $referenceId): int
    {
        return (int) PrintedDocument::query()
            ->where('document', $document)
            ->where('reference_id', $referenceId)
            ->max('copy');
    }

    private function create(string $document, Sale|CounterOrder $reference, User $by, int $copy, bool $automatic): PrintedDocument
    {
        return PrintedDocument::query()->create([
            'branch_id' => $reference->branch_id,
            'document' => $document,
            'reference_type' => $reference instanceof Sale ? 'sale' : 'counter_order',
            'reference_id' => $reference->getKey(),
            'copy' => $copy,
            'automatic' => $automatic,
            'printed_by' => $by->id,
            'printed_at' => now(),
        ]);
    }
}
