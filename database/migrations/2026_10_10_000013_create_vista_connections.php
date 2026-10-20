<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vista_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->unique()->constrained()->restrictOnDelete();
            $t->string('provider');
            $t->string('subject', 100);
            $t->string('store_id', 100);
            $t->string('store_name');
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('access_token')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('status')->default('connected');
            $t->timestamp('synced_at')->nullable();
            $t->string('last_error')->nullable();
            $t->json('checkpoint')->nullable();
            $t->uuid('sync_run')->nullable();
            $t->timestamps();
            $t->unique(['shop_id', 'id']);
            $t->unique(['provider', 'store_id'], 'vista_store_identity_unique');
            $t->unique(['provider', 'subject'], 'vista_subject_unique');
        });
        Schema::create('vista_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('vista_connection_id');
            $t->foreign(['shop_id', 'vista_connection_id'])->references(['shop_id', 'id'])->on('vista_connections')->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('remote_id', 100);
            $t->json('payload');
            $t->uuid('seen_run');
            $t->timestamp('observed_at');
            $t->boolean('unavailable')->default(false);
            $t->timestamps();
            $t->unique(['vista_connection_id', 'kind', 'remote_id'], 'vista_record_unique');
            $t->index(['shop_id', 'kind']);
        });
        Schema::create('vista_publications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('vista_connection_id');
            $t->foreign(['shop_id', 'vista_connection_id'])->references(['shop_id', 'id'])->on('vista_connections')->restrictOnDelete();
            $t->unsignedBigInteger('product_id');
            $t->foreign(['shop_id', 'product_id'])->references(['shop_id', 'id'])->on('products')->restrictOnDelete();
            $t->unique(['shop_id', 'id']);
            $t->uuid('external_key')->unique();
            $t->string('remote_id')->nullable();
            $t->string('remote_version', 64)->nullable();
            $t->boolean('visible')->default(false);
            $t->json('mapping')->nullable();
            $t->timestamps();
            $t->unique(['vista_connection_id', 'product_id'], 'vista_publication_unique');
        });
        Schema::create('vista_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('vista_publication_id');
            $t->foreign(['shop_id', 'vista_publication_id'])->references(['shop_id', 'id'])->on('vista_publications')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('operation_key')->unique();
            $t->string('request_hash', 64);
            $t->json('payload');
            $t->json('image_map');
            $t->unsignedInteger('allocated_quantity');
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
            $t->index(['status', 'next_attempt_at']);
        });
        foreach (['integrations.read', 'integrations.write', 'integrations.publish'] as $permission) {
            foreach (['owner' => $permission, 'system_admin' => 'shops.'.$permission] as $role => $name) {
                $id = DB::table('permissions')->insertGetId(['name' => $name]);
                DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
            }
        }
    }

    public function down(): void
    {
        foreach (['vista_outbox', 'vista_publications', 'vista_records', 'vista_connections'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('permissions')->where('name', 'like', 'integrations.%')->orWhere('name', 'like', 'shops.integrations.%')->delete();
    }
};
