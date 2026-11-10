<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A programme groups projects under one aim. It holds no money or tasks of
 * its own: what it shows adds up its projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('code', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUuid('manager_id')->nullable()->constrained('people');
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'id'], 'programmes_tenant_key');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUuid('programme_id')->nullable()->constrained();
            $table->index(['organization_id', 'programme_id']);
        });

        // As for financial records: the database refuses another organization's.
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreign(['organization_id', 'programme_id'], 'projects_programme_id_tenant_fk')->references(['organization_id', 'id'])->on('programmes');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropForeign('projects_programme_id_tenant_fk');
            });
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'programme_id']);
            $table->dropConstrainedForeignId('programme_id');
        });

        Schema::dropIfExists('programmes');
    }
};
