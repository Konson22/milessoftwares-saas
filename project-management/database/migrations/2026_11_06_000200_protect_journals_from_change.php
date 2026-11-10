<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Journal lines are already append-only on PostgreSQL. This protects the
 * journal itself: its date, memo and source can't change and it can't be
 * deleted. Only the links set after posting may be filled in, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journals_protected() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'journals are append-only: reverse a journal instead of deleting it';
                END IF;
                IF (NEW.id, NEW.organization_id, NEW.number, NEW.entry_date, NEW.memo, NEW.source_app, NEW.source_type, NEW.source_reference, NEW.branch_id, NEW.created_by, NEW.created_at)
                    IS DISTINCT FROM
                   (OLD.id, OLD.organization_id, OLD.number, OLD.entry_date, OLD.memo, OLD.source_app, OLD.source_type, OLD.source_reference, OLD.branch_id, OLD.created_by, OLD.created_at) THEN
                    RAISE EXCEPTION 'journals are append-only: post a reversal instead of editing a journal';
                END IF;
                IF (OLD.reversal_of_id IS NOT NULL AND NEW.reversal_of_id IS DISTINCT FROM OLD.reversal_of_id)
                    OR (OLD.reversed_by_id IS NOT NULL AND NEW.reversed_by_id IS DISTINCT FROM OLD.reversed_by_id)
                    OR (OLD.approved_by IS NOT NULL AND NEW.approved_by IS DISTINCT FROM OLD.approved_by)
                    OR (OLD.approved_at IS NOT NULL AND NEW.approved_at IS DISTINCT FROM OLD.approved_at)
                    OR (OLD.recurring_occurrence IS NOT NULL AND NEW.recurring_occurrence IS DISTINCT FROM OLD.recurring_occurrence) THEN
                    RAISE EXCEPTION 'journals are append-only: a reversal or approval link can only be set once';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement('CREATE TRIGGER journals_protected BEFORE UPDATE OR DELETE ON journals FOR EACH ROW EXECUTE FUNCTION journals_protected()');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS journals_protected ON journals');
        DB::statement('DROP FUNCTION IF EXISTS journals_protected()');
    }
};
