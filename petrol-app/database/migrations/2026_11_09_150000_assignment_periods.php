<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('shared_pumps')->default(false);
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable();
            $table->uuid('pump_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('opening_milli')->nullable();
            $table->unsignedBigInteger('closing_milli')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->string('notes')->nullable();
            $table->dropUnique(['shift_id', 'nozzle_id']);
            $table->index(['shift_id', 'nozzle_id', 'status']);
        });

        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->uuid('assignment_id')->nullable();
            $table->index('assignment_id');
        });

        Schema::create('attendant_handovers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('shift_id')->constrained();
            $table->foreignUuid('nozzle_id')->constrained();
            $table->foreignUuid('outgoing_assignment_id')->constrained('shift_assignments');
            $table->foreignUuid('incoming_attendant_id')->constrained('attendants');
            $table->foreignUuid('incoming_assignment_id')->nullable()->constrained('shift_assignments');
            $table->unsignedBigInteger('closing_milli');
            $table->unsignedBigInteger('opening_milli');
            $table->string('status', 16);
            $table->string('event_reason')->nullable();
            $table->string('notes')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at');
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('shift_breaks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('shift_id')->constrained();
            $table->foreignUuid('assignment_id')->constrained('shift_assignments');
            $table->foreignUuid('nozzle_id')->constrained();
            $table->foreignUuid('attendant_id')->constrained();
            $table->string('reason', 32);
            $table->boolean('nozzle_available')->default(false);
            $table->unsignedBigInteger('reading_milli');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignUuid('replacement_assignment_id')->nullable()->constrained('shift_assignments');
            $table->foreignUuid('return_assignment_id')->nullable()->constrained('shift_assignments');
            $table->foreignUuid('started_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('shift_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('shift_id')->constrained();
            $table->foreignUuid('assignment_id')->nullable()->constrained('shift_assignments');
            $table->foreignUuid('handover_id')->nullable()->constrained('attendant_handovers');
            $table->string('type', 32);
            $table->string('description');
            $table->string('severity', 16);
            $table->string('status', 16);
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users');
            $table->string('resolution')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['shift_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_exceptions');
        Schema::dropIfExists('shift_breaks');
        Schema::dropIfExists('attendant_handovers');
        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->dropIndex(['assignment_id']);
            $table->dropColumn('assignment_id');
        });
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropIndex(['shift_id', 'nozzle_id', 'status']);
            $table->unique(['shift_id', 'nozzle_id']);
            $table->dropColumn([
                'branch_id', 'pump_id', 'started_at', 'ended_at', 'status',
                'opening_milli', 'closing_milli', 'assigned_by', 'verified_by', 'notes',
            ]);
        });
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('shared_pumps');
        });
    }
};
