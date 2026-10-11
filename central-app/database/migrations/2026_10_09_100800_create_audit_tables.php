<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only, hash-chained audit trail. No foreign keys: audit rows must
     * outlive the users and organizations they describe.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            // The sequence orders the hash chain; the UUID is the public reference.
            $table->bigIncrements('sequence');
            $table->uuid('id')->unique();
            $table->timestampTz('occurred_at');
            $table->string('actor_type', 32);
            $table->uuid('actor_id')->nullable();
            $table->uuid('organization_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            // Plain text, not inet: inet rewrites addresses and would break the hash.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('context')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['actor_type', 'actor_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });

        // Reject UPDATE always; reject DELETE unless the retention job has
        // explicitly opened a purge window in its own transaction with
        // SET LOCAL miles.audit_purge = 'on'.
        DB::unprepared(<<<'SQL'
            -- OR REPLACE: migrate:fresh drops tables but not functions.
            CREATE OR REPLACE FUNCTION audit_logs_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('miles.audit_purge', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'audit_logs is append-only (% rejected)', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_guard();

            CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_guard();
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs');
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_guard()');
    }
};
