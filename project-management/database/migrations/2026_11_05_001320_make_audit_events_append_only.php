<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The database itself keeps the audit log: a row is never changed or removed.
     * One exception: audit_events.user_id is set to null when its user is removed
     * (the foreign key's own rule), and nothing else may change with it.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.user_id IS NULL AND (to_jsonb(NEW) - 'user_id') = (to_jsonb(OLD) - 'user_id') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'audit_events is append-only';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION audit_events_append_only()');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events');
        DB::statement('DROP FUNCTION IF EXISTS audit_events_append_only()');
    }
};
