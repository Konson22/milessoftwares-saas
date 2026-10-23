<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promotions: a percentage off one item, or a whole category, for some
     * days, in every branch or one (App\Modules\Sales\Actions\Promotions).
     * The till applies them by itself; each sale line keeps the promotion
     * it was sold under.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('application', 32);
            $table->string('name');
            $table->unsignedTinyInteger('percent_off');
            // One of the two: an item, or every item of a category.
            $table->uuid('item_id')->nullable();
            $table->string('category', 64)->nullable();
            // Null: every branch.
            $table->uuid('branch_id')->nullable();
            $table->date('starts_on');
            // Null: until it is ended.
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->uuid('created_by');
            $table->timestamps();

            $table->index(['organization_id', 'application', 'active']);
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->uuid('promotion_id')->nullable()->after('discount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('promotion_id');
        });
        Schema::dropIfExists('promotions');
    }
};
