<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedBigInteger('parent_product_id')->nullable();
            $t->json('variation_attributes')->nullable();
            $t->char('variation_key', 64)->nullable();
            $t->foreign(['shop_id', 'parent_product_id'], 'product_variation_parent')
                ->references(['shop_id', 'id'])->on('products')->restrictOnDelete();
            $t->unique(['shop_id', 'parent_product_id', 'variation_key'], 'product_variation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropForeign('product_variation_parent');
            $t->dropUnique('product_variation_unique');
            $t->dropColumn(['parent_product_id', 'variation_attributes', 'variation_key']);
        });
    }
};
