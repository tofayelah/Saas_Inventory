<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('crm_deal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('deal_id');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 2)->default(1.00);
            $table->decimal('unit_price', 15, 2)->default(0.00);
            $table->decimal('discount_amount', 15, 2)->default(0.00);
            $table->decimal('subtotal', 15, 2)->default(0.00);
            $table->timestamps();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_deal_items_team_id_id_unique');

            // Query optimization index
            $table->index(['team_id', 'deal_id'], 'crm_deal_items_team_deal_index');

            // Tier 1 Composite Foreign Key to crm_deals
            $table->foreign(['team_id', 'deal_id'], 'fk_crm_deal_items_deal')
                ->references(['team_id', 'id'])
                ->on('crm_deals')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_deal_items');
    }
};
