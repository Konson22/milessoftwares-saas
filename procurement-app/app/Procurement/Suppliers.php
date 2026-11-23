<?php

namespace App\Procurement;

use App\Models\User;
use App\Procurement\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The organization's supplier register, owned by Procurement (ADR-0023
 * §2.4). Obvious duplicates are refused (same name once punctuation and
 * company suffixes are set aside, or the same tax number). Suppliers are
 * never deleted: they are made inactive or suspended, and keep their
 * history.
 */
final class Suppliers
{
    private const SUFFIXES = ['ltd', 'limited', 'llc', 'inc', 'co', 'company', 'plc', 'corp', 'corporation', 'enterprises', 'enterprise', 'and', 'the'];

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?Supplier $supplier, array $data, User $by): Supplier
    {
        return DB::transaction(function () use ($supplier, $data, $by) {
            $key = self::nameKey((string) $data['name']);
            $tax = isset($data['tax_number']) && trim((string) $data['tax_number']) !== '' ? strtoupper(preg_replace('/\s+/', '', (string) $data['tax_number'])) : null;

            $sameName = Supplier::query()->where('name_key', $key)->when($supplier, fn ($q) => $q->whereKeyNot($supplier->id))->first();
            if ($sameName !== null) {
                throw ValidationException::withMessages(['name' => "This looks like {$sameName->name}, already a supplier."]);
            }
            if ($tax !== null) {
                $sameTax = Supplier::query()->where('tax_number', $tax)->when($supplier, fn ($q) => $q->whereKeyNot($supplier->id))->first();
                if ($sameTax !== null) {
                    throw ValidationException::withMessages(['tax_number' => "{$sameTax->name} already has this tax number."]);
                }
            }

            $model = $supplier ?? new Supplier(['created_by' => $by->id, 'status' => 'active']);
            $model->fill([
                'name' => trim((string) $data['name']),
                'name_key' => $key,
                'type' => $data['type'],
                'contact_person' => $data['contact_person'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'country' => isset($data['country']) && $data['country'] !== '' ? strtoupper((string) $data['country']) : null,
                'registration_number' => $data['registration_number'] ?? null,
                'tax_number' => $tax,
                'categories' => array_values(array_unique(array_filter(array_map('trim', (array) ($data['categories'] ?? []))))) ?: null,
                'notes' => $data['notes'] ?? null,
            ]);
            $changes = $model->exists ? array_keys($model->getDirty()) : null;
            $model->save();

            AuditTrail::record($supplier === null ? 'supplier.created' : 'supplier.updated', $model, $by, ['changed' => $changes]);

            return $model;
        });
    }

    public static function setStatus(Supplier $supplier, string $status, ?string $reason, User $by): void
    {
        if (! in_array($status, Supplier::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Active, inactive or suspended.']);
        }
        if ($status === 'suspended' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the supplier is suspended.']);
        }

        DB::transaction(function () use ($supplier, $status, $reason, $by) {
            $from = $supplier->status;
            $supplier->forceFill(['status' => $status, 'status_reason' => $status === 'active' ? null : $reason])->save();
            AuditTrail::record('supplier.status', $supplier, $by, ['from' => $from, 'to' => $status, 'reason' => $reason]);
        });
    }

    /**
     * An active supplier of the organization, or a validation error.
     */
    public static function active(string $id, string $field = 'supplier_id'): Supplier
    {
        $supplier = Supplier::query()->find($id);
        if ($supplier === null) {
            throw ValidationException::withMessages([$field => 'Choose one of the organization\'s suppliers.']);
        }
        if (! $supplier->isActive()) {
            throw ValidationException::withMessages([$field => "{$supplier->name} is {$supplier->status}: nothing new can be placed with them."]);
        }

        return $supplier;
    }

    public static function nameKey(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower(trim($name))) ?: [];
        $kept = array_values(array_filter($words, fn ($w) => $w !== '' && ! in_array($w, self::SUFFIXES, true)));

        return implode(' ', $kept !== [] ? $kept : array_filter($words));
    }
}
