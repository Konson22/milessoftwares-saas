<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who changed a control, approved, paid or reversed what, and when.
        // Append only: App\Models\AuditEvent refuses updates and deletes.
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('actor_id')->nullable()->constrained('users');
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at');

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });

        // The rules in force when a request or held journal was raised.
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->json('policy')->nullable();
        });
        Schema::table('journal_drafts', function (Blueprint $table) {
            $table->json('policy')->nullable();
        });

        // Money leaving a bank is always held from the limit up, and one
        // person never acts twice on a payment that needed approval: neither
        // is a setting any more (catalog 1.10.0).
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['payment_approval', 'payment_separation']);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('payment_approval')->default(false);
            $table->boolean('payment_separation')->default(true);
        });
        Schema::table('journal_drafts', function (Blueprint $table) {
            $table->dropColumn('policy');
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('policy');
        });
        Schema::dropIfExists('audit_events');
    }
};
