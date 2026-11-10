<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('is_main')->default(false);
        });

        foreach (DB::table('stations')->distinct()->pluck('organization_id') as $organizationId) {
            $id = DB::table('stations')
                ->where('organization_id', $organizationId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('id');

            if ($id !== null) {
                DB::table('stations')->where('id', $id)->update(['is_main' => true]);
            }
        }

        $predicate = Schema::getConnection()->getDriverName() === 'pgsql' ? 'is_main' : 'is_main = 1';
        DB::statement("create unique index stations_one_main_branch on stations (organization_id) where {$predicate}");
    }

    public function down(): void
    {
        DB::statement('drop index if exists stations_one_main_branch');

        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('is_main');
        });
    }
};
