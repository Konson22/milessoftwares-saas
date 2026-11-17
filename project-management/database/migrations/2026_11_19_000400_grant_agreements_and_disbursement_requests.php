<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grant agreements both parties authorize, and disbursements the recipient
 * requests and the donor approves (Phase 5, App\Actions\GrantAgreements).
 * Only for a grant whose funder is a donor connected on Tatook. Additive: a
 * grant without an agreement works as before; existing payments read as
 * planned, or received when they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            // null (none), proposed, approved, declined.
            $table->string('agreement_status', 16)->nullable();
            // What the donor approved: the ceiling for the grant and its payments.
            $table->bigInteger('agreement_amount_minor')->nullable();
            $table->date('agreement_end_on')->nullable();
            $table->timestamp('agreement_proposed_at')->nullable();
            $table->uuid('agreement_proposed_by')->nullable();
            $table->timestamp('agreement_decided_at')->nullable();
            $table->uuid('agreement_decided_by')->nullable();
            $table->text('agreement_note')->nullable();
        });

        Schema::table('grant_disbursements', function (Blueprint $table) {
            // planned, requested, approved, rejected, sent, received, disputed.
            $table->string('status', 16)->default('planned');
            $table->timestamp('requested_at')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->text('decision_note')->nullable();
            // Recorded by the donor that sent it: not a Finance confirmation.
            $table->date('sent_on')->nullable();
            $table->string('sent_reference', 100)->nullable();
            $table->uuid('sent_by')->nullable();
            $table->uuid('received_by')->nullable();
            $table->text('dispute_note')->nullable();
        });
        DB::table('grant_disbursements')->whereNotNull('received_on')->update(['status' => 'received']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE grants ADD CONSTRAINT grants_agreement_status_check CHECK (agreement_status IS NULL OR agreement_status IN ('proposed', 'approved', 'declined'))");
            DB::statement('ALTER TABLE grants ADD CONSTRAINT grants_agreement_amount_check CHECK (agreement_amount_minor IS NULL OR agreement_amount_minor > 0)');
            DB::statement("ALTER TABLE grant_disbursements ADD CONSTRAINT grant_disbursements_status_check CHECK (status IN ('planned', 'requested', 'approved', 'rejected', 'sent', 'received', 'disputed'))");
            DB::statement("ALTER TABLE grant_disbursements ADD CONSTRAINT grant_disbursements_received_check CHECK ((status = 'received') = (received_on IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::table('grants')->whereNotNull('agreement_status')->exists()
            || DB::table('grant_disbursements')->whereNotIn('status', ['planned', 'received'])->exists()) {
            throw new RuntimeException('Refusing to roll back: grant agreements or disbursement requests would be lost.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grant_disbursements DROP CONSTRAINT IF EXISTS grant_disbursements_received_check');
            DB::statement('ALTER TABLE grant_disbursements DROP CONSTRAINT IF EXISTS grant_disbursements_status_check');
            DB::statement('ALTER TABLE grants DROP CONSTRAINT IF EXISTS grants_agreement_amount_check');
            DB::statement('ALTER TABLE grants DROP CONSTRAINT IF EXISTS grants_agreement_status_check');
        }
        Schema::table('grant_disbursements', fn (Blueprint $table) => $table->dropColumn(['status', 'requested_at', 'requested_by', 'decided_at', 'decided_by', 'decision_note', 'sent_on', 'sent_reference', 'sent_by', 'received_by', 'dispute_note']));
        Schema::table('grants', fn (Blueprint $table) => $table->dropColumn(['agreement_status', 'agreement_amount_minor', 'agreement_end_on', 'agreement_proposed_at', 'agreement_proposed_by', 'agreement_decided_at', 'agreement_decided_by', 'agreement_note']));
    }
};
