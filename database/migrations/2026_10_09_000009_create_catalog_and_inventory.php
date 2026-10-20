<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $t) {
            $t->char('currency', 3)->nullable();
            $t->unsignedInteger('settings_version')->default(1);
            $t->json('notification_preferences')->nullable();
        });
        Schema::create('product_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->string('name_key', 100);
            $t->text('description')->nullable();
            $t->string('status', 20)->default('active');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['shop_id', 'id']);
            $t->unique(['shop_id', 'name_key']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('category_id');
            $t->foreign(['shop_id', 'category_id'])->references(['shop_id', 'id'])->on('product_categories')->restrictOnDelete();
            $t->string('name', 200);
            $t->string('sku', 80);
            $t->string('brand', 100);
            $t->string('model', 100);
            $t->string('color', 80);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('price_minor');
            $t->char('currency', 3);
            $t->unsignedTinyInteger('price_decimals');
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('low_stock_threshold')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->uuid('creation_key');
            $t->char('creation_hash', 64);
            $t->timestamps();
            $t->unique(['shop_id', 'id']);
            $t->unique(['shop_id', 'sku']);
            $t->unique(['shop_id', 'creation_key']);
            $t->index(['shop_id', 'status', 'category_id']);
            $t->index(['shop_id', 'brand']);
        });
        Schema::create('product_images', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->foreign(['shop_id', 'product_id'])->references(['shop_id', 'id'])->on('products')->restrictOnDelete();
            $t->string('storage_key')->unique();
            $t->string('display_key')->nullable();
            $t->string('filename', 240);
            $t->string('mime', 80);
            $t->unsignedBigInteger('size');
            $t->char('sha256', 64);
            $t->uuid('upload_key');
            $t->string('scan_status', 20)->default('pending');
            $t->string('scan_error', 80)->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['shop_id', 'product_id', 'id']);
            $t->unique(['shop_id', 'upload_key']);
        });
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedBigInteger('primary_image_id')->nullable();
            $t->foreign(['shop_id', 'id', 'primary_image_id'], 'product_primary_image_owned')->references(['shop_id', 'product_id', 'id'])->on('product_images')->restrictOnDelete();
        });
        Schema::create('stock_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->unique()->constrained()->restrictOnDelete();
            $t->string('name', 100)->default('Main stock');
            $t->timestamps();
            $t->unique(['shop_id', 'id']);
        });
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('location_id');
            $t->foreign(['shop_id', 'product_id'])->references(['shop_id', 'id'])->on('products')->restrictOnDelete();
            $t->foreign(['shop_id', 'location_id'])->references(['shop_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $t->integer('on_hand')->default(0);
            $t->integer('reserved')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('low_notified')->default(false);
            $t->timestamps();
            $t->unique(['shop_id', 'product_id']);
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('location_id');
            $t->foreign(['shop_id', 'product_id'])->references(['shop_id', 'id'])->on('products')->restrictOnDelete();
            $t->foreign(['shop_id', 'location_id'])->references(['shop_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $t->string('kind', 30);
            $t->integer('quantity_delta');
            $t->integer('reserved_delta');
            $t->integer('on_hand_after');
            $t->integer('reserved_after');
            $t->string('request_key', 150);
            $t->char('request_hash', 64);
            $t->unsignedBigInteger('reverses_id')->nullable();
            $t->string('subject_type', 40)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['shop_id', 'id']);
            $t->unique(['shop_id', 'request_key']);
            $t->unique(['shop_id', 'reverses_id']);
            $t->foreign(['shop_id', 'reverses_id'])->references(['shop_id', 'id'])->on('stock_movements')->restrictOnDelete();
            $t->index(['shop_id', 'product_id', 'id']);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER stock_balance_valid_'.strtolower($operation)." BEFORE $operation ON stock_balances WHEN NEW.on_hand < 0 OR NEW.reserved < 0 OR NEW.reserved > NEW.on_hand BEGIN SELECT RAISE(ABORT, 'Invalid stock balance'); END");
            }
        } else {
            DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balance_valid CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)');
        }
        Schema::create('shop_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('event_key', 180);
            $t->string('type', 60);
            $t->string('title', 200);
            $t->string('url', 255);
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['shop_id', 'user_id', 'event_key']);
            $t->index(['shop_id', 'user_id', 'read_at']);
        });
        $role = DB::table('roles')->where('name', 'owner')->value('id');
        foreach (['products.read', 'products.write', 'stock.read', 'stock.write', 'notices.read', 'notices.write'] as $name) {
            $id = DB::table('permissions')->insertGetId(['name' => $name]);
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_notices');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_locations');
        Schema::table('products', function (Blueprint $t) {
            $t->dropForeign('product_primary_image_owned');
            $t->dropColumn('primary_image_id');
        });
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn(['currency', 'settings_version', 'notification_preferences']));
        DB::table('permissions')->whereIn('name', ['products.read', 'products.write', 'stock.read', 'stock.write', 'notices.read', 'notices.write'])->delete();
    }
};
