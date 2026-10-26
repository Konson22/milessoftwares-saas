<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Gap-free running numbers per organization: ADM-00001, INV-00001, RCT-00001.
 * Call inside the transaction that creates the document, so a rolled-back
 * document gives its number back.
 */
class DocumentNumbers
{
    public function __construct(private readonly CurrentTenant $tenant) {}

    public function next(string $type, string $prefix, int $digits = 5): string
    {
        $organizationId = $this->tenant->require()->id;

        // Create the counter once; then lock it for this transaction.
        DB::table('document_numbers')->insertOrIgnore([
            'organization_id' => $organizationId,
            'type' => $type,
            'last_number' => 0,
        ]);

        $last = (int) DB::table('document_numbers')
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->lockForUpdate()
            ->value('last_number');

        DB::table('document_numbers')
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->update(['last_number' => $last + 1]);

        return $prefix.'-'.str_pad((string) ($last + 1), $digits, '0', STR_PAD_LEFT);
    }
}
