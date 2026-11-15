<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what in M&E, and when. Rows are only ever added (PostgreSQL
 * refuses changes and deletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mel_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // programme.created, submission.verified, indicator.revised...
            $table->string('action', 48);
            $table->string('subject_type', 32);
            $table->uuid('subject_id')->nullable();
            // "WASH · Clean water", "WASH-OP1.2 · 2026 Q1": readable after the subject is gone.
            $table->string('subject_label');
            // Null for changes made by another product's events.
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('user_name')->nullable();
            $table->json('details')->nullable();
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION mel_audit_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'the M&E audit trail is append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER mel_audit_append_only BEFORE UPDATE OR DELETE ON mel_audit_events FOR EACH ROW EXECUTE FUNCTION mel_audit_append_only()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS mel_audit_append_only ON mel_audit_events');
            DB::statement('DROP FUNCTION IF EXISTS mel_audit_append_only()');
        }

        Schema::dropIfExists('mel_audit_events');
    }
};
