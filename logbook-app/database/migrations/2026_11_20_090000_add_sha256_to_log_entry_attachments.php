<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The phone app uploads files one by one and resends after a lost reply:
 * the file's SHA-256 lets the server answer a repeat with the attachment it
 * already has instead of storing a copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_entry_attachments', function (Blueprint $table) {
            $table->char('sha256', 64)->nullable()->after('mime');
            $table->index(['organization_id', 'log_entry_id', 'field', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::table('log_entry_attachments', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'log_entry_id', 'field', 'sha256']);
            $table->dropColumn('sha256');
        });
    }
};
