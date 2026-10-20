<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', fn (Blueprint $t) => $t->unsignedBigInteger('order_sequence')->default(0));
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('prescription_id')->nullable();
            $t->string('number', 30);
            $t->string('status', 20)->default('draft');
            $t->string('channel', 30)->default('in_store');
            $t->char('currency', 3);
            $t->unsignedTinyInteger('price_decimals');
            $t->unsignedBigInteger('total_minor');
            $t->json('customer_snapshot');
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->uuid('creation_key');
            $t->char('creation_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            foreach (['confirmed_at', 'fulfilled_at', 'cancelled_at', 'reversed_at'] as $f) {
                $t->timestamp($f)->nullable();
            }
            $t->timestamps();
            $t->unique(['shop_id', 'id']);
            $t->unique(['shop_id', 'customer_id', 'id']);
            $t->unique(['shop_id', 'number']);
            $t->unique(['shop_id', 'creation_key']);
            $t->foreign(['shop_id', 'customer_id'])->references(['shop_id', 'id'])->on('customers')->restrictOnDelete();
            $t->foreign(['shop_id', 'customer_id', 'prescription_id'], 'order_prescription_same_customer')->references(['shop_id', 'customer_id', 'id'])->on('optical_records')->restrictOnDelete();
            $t->index(['shop_id', 'status', 'created_at']);
            $t->index(['shop_id', 'customer_id', 'created_at']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            $t->string('name', 200);
            $t->string('sku', 80);
            $t->string('brand', 100);
            $t->string('model', 100);
            $t->string('color', 80);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_minor');
            $t->unsignedBigInteger('total_minor');
            $t->foreign(['shop_id', 'order_id'])->references(['shop_id', 'id'])->on('orders')->restrictOnDelete();
            $t->foreign(['shop_id', 'product_id'])->references(['shop_id', 'id'])->on('products')->restrictOnDelete();
            $t->unique(['shop_id', 'order_id', 'product_id']);
            $t->unique(['shop_id', 'id']);
        });
        Schema::create('order_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('order_id');
            $t->string('action', 40);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->unsignedInteger('version');
            $t->text('reason')->nullable();
            $t->boolean('stock_returned')->nullable();
            $t->uuid('request_key')->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->foreign(['shop_id', 'order_id'])->references(['shop_id', 'id'])->on('orders')->restrictOnDelete();
            $t->unique(['shop_id', 'request_key']);
            $t->index(['shop_id', 'order_id', 'id']);
        });
        Schema::create('sale_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('order_id');
            $t->string('kind', 20);
            $t->char('currency', 3);
            $t->unsignedTinyInteger('price_decimals');
            $t->unsignedBigInteger('amount_minor');
            $t->unsignedBigInteger('reverses_id')->nullable();
            $t->boolean('stock_returned')->default(false);
            $t->text('reason')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->foreign(['shop_id', 'customer_id', 'order_id'])->references(['shop_id', 'customer_id', 'id'])->on('orders')->restrictOnDelete();
            $t->unique(['shop_id', 'id']);
            $t->unique(['shop_id', 'order_id', 'kind']);
            $t->unique(['shop_id', 'reverses_id']);
            $t->foreign(['shop_id', 'reverses_id'])->references(['shop_id', 'id'])->on('sale_entries')->restrictOnDelete();
            $t->index(['shop_id', 'created_at']);
            $t->index(['shop_id', 'customer_id', 'created_at']);
        });
        Schema::table('appointments', function (Blueprint $t) {
            $t->unsignedBigInteger('order_id')->nullable();
            $t->foreign(['shop_id', 'customer_id', 'order_id'], 'appointment_order_same_customer')->references(['shop_id', 'customer_id', 'id'])->on('orders')->restrictOnDelete();
        });
        $role = DB::table('roles')->where('name', 'owner')->value('id');
        foreach (['orders.read', 'orders.write', 'orders.fulfil', 'sales.reverse', 'reports.read', 'reports.export'] as $name) {
            $id = DB::table('permissions')->insertGetId(['name' => $name]);
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->dropForeign('appointment_order_same_customer');
            $t->dropColumn('order_id');
        });
        foreach (['sale_entries', 'order_events', 'order_items', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn('order_sequence'));
        DB::table('permissions')->whereIn('name', ['orders.read', 'orders.write', 'orders.fulfil', 'sales.reverse', 'reports.read', 'reports.export'])->delete();
    }
};
