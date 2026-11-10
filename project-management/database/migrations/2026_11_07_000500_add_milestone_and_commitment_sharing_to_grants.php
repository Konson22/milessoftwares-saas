<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestones and commitments become their own sharing switches, apart from
 * progress and spending. Off by default; a grant already sharing progress or
 * spending keeps showing what it showed, so no donor loses or gains anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->boolean('portal_milestones')->default(false)->after('portal_progress');
            $table->boolean('portal_commitments')->default(false)->after('portal_spending');
        });

        DB::table('grants')->where('portal_progress', true)->update(['portal_milestones' => true]);
        DB::table('grants')->where('portal_spending', true)->update(['portal_commitments' => true]);
    }

    public function down(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->dropColumn(['portal_milestones', 'portal_commitments']);
        });
    }
};
