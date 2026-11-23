<?php

namespace App\Procurement;

use App\Models\User;
use App\Procurement\Models\Award;
use App\Procurement\Models\Document;
use App\Procurement\Models\Plan;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Quotation;
use App\Procurement\Models\Receipt;
use App\Procurement\Models\SourcingEvent;
use App\Procurement\Models\Supplier;
use App\Procurement\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Supporting documents on any procurement record: quotations, delivery
 * notes, invoices, approvals evidence. Kept on the private disk under the
 * organization; served only to someone who can open the record itself.
 */
final class Documents
{
    public const MAX_KILOBYTES = 20480;

    public const MIMES = 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt';

    public const SUBJECTS = ['plan', 'requisition', 'sourcing', 'quotation', 'award', 'order', 'receipt', 'invoice', 'supplier'];

    /**
     * The record a document may be attached to, as the member reaches it
     * (404 otherwise), and whether they may add to it.
     *
     * @return array{0: Model, 1: bool}
     */
    public static function subject(string $type, string $id): array
    {
        $model = match ($type) {
            'requisition' => Access::requisition($id),
            'plan' => self::visible(Plan::class, $id),
            'sourcing' => self::visible(SourcingEvent::class, $id),
            'quotation' => self::visible(Quotation::class, $id),
            'award' => self::visible(Award::class, $id),
            'order' => self::visible(PurchaseOrder::class, $id),
            'receipt' => self::visible(Receipt::class, $id),
            'invoice' => self::visible(SupplierInvoice::class, $id),
            'supplier' => self::visible(Supplier::class, $id),
            default => abort(404),
        };

        $mayAdd = match ($type) {
            'requisition' => $model->getAttribute('requester_id') === auth()->id() || Gate::allows('procurement.sourcing.manage'),
            'plan' => Gate::allows('procurement.plans.manage'),
            'sourcing', 'quotation', 'award' => Gate::allows('procurement.sourcing.manage'),
            'order' => Gate::allows('procurement.orders.manage'),
            'receipt' => Gate::allows('procurement.receive'),
            'invoice' => Gate::allows('procurement.invoices.manage'),
            'supplier' => Gate::allows('procurement.suppliers.manage'),
        };

        return [$model, $mayAdd];
    }

    public static function attach(string $type, Model $subject, UploadedFile $file, User $by): Document
    {
        $organization = (string) $subject->getAttribute('organization_id');
        $path = $file->store("procurement/{$organization}/{$type}", 'local');

        try {
            return DB::transaction(fn () => Document::query()->create([
                'subject_type' => $type,
                'subject_id' => $subject->getKey(),
                'name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $path,
                'size' => (int) $file->getSize(),
                'mime' => $file->getMimeType(),
                'uploaded_by' => $by->id,
            ]));
        } catch (\Throwable $e) {
            Storage::disk('local')->delete((string) $path);

            throw $e;
        }
    }

    /**
     * @return list<array{id: string, name: string, size: int, uploadedAt: string|null}>
     */
    public static function for(string $type, Model $subject): array
    {
        return array_values(Document::query()->where('subject_type', $type)->where('subject_id', $subject->getKey())->orderBy('created_at')->get()
            ->map(fn (Document $d) => ['id' => $d->id, 'name' => $d->name, 'size' => $d->size, 'uploadedAt' => $d->created_at?->toIso8601String()])
            ->all());
    }

    /**
     * @param  class-string<Model>  $class
     */
    private static function visible(string $class, string $id): Model
    {
        abort_unless(Access::seesAll(), 404);

        return $class::query()->findOrFail($id);
    }
}
