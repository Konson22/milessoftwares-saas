<?php

namespace App\Procurement;

use App\Models\User;
use App\Models\Workspace;
use App\Procurement\Models\ApprovalRule;
use App\Procurement\Models\AuditEvent;
use App\Procurement\Models\Award;
use App\Procurement\Models\Plan;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Quotation;
use App\Procurement\Models\Receipt;
use App\Procurement\Models\Requisition;
use App\Procurement\Models\SourcingEvent;
use App\Procurement\Models\Supplier;
use App\Procurement\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Who did what in Procurement. Called inside the change's transaction, so
 * an entry exists exactly when the change does. Append-only.
 */
final class AuditTrail
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(string $action, Model $subject, ?User $user, array $details = []): AuditEvent
    {
        [$type, $label] = self::describe($subject);

        return AuditEvent::query()->create([
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'subject_label' => mb_substr($label, 0, 255),
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'details' => array_filter($details, fn ($value) => $value !== null) ?: null,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function describe(Model $subject): array
    {
        return match (true) {
            $subject instanceof Plan => ['plan', $subject->number.' · '.$subject->title],
            $subject instanceof Requisition => ['requisition', $subject->number.' · '.$subject->title],
            $subject instanceof SourcingEvent => ['sourcing', $subject->number.' · '.$subject->title],
            $subject instanceof Quotation => ['quotation', $subject->supplier->name.' · '.$subject->event->number],
            $subject instanceof Award => ['award', $subject->event->number.' · '.$subject->supplier->name],
            $subject instanceof PurchaseOrder => ['order', $subject->number.' · '.$subject->supplier->name],
            $subject instanceof Receipt => ['receipt', $subject->number.' · '.$subject->order->number],
            $subject instanceof SupplierInvoice => ['invoice', $subject->number.' · '.$subject->supplier->name],
            $subject instanceof Supplier => ['supplier', $subject->name],
            $subject instanceof ApprovalRule => ['approval_rule', $subject->getAttribute('name')],
            $subject instanceof Workspace => ['settings', 'Procurement settings'],
            default => throw new InvalidArgumentException('Procurement does not audit '.$subject::class),
        };
    }
}
