<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->date('date_of_birth')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('phone_normalized', 20)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('street_address')->nullable();
            $t->string('city', 100)->nullable();
            $t->string('postal_code', 32)->nullable();
            $t->string('sex', 40)->nullable();
            $t->string('status', 20)->default('active');
            $t->unsignedInteger('version')->default(1);
            $t->uuid('creation_key');
            $t->char('creation_hash', 64);
            $t->string('source', 30)->default('manual');
            $t->timestamps();
            $t->unique(['shop_id', 'id']);
            $t->unique(['shop_id', 'creation_key']);
            $t->index(['shop_id', 'status', 'last_name']);
            $t->index(['shop_id', 'email']);
            $t->index(['shop_id', 'phone_normalized']);
        });
        Schema::create('customer_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('customer_id');
            $t->foreign(['shop_id', 'customer_id'])->references(['shop_id', 'id'])->on('customers')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 80);
            $t->string('subject_type', 50)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('changed_fields')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['shop_id', 'customer_id', 'created_at']);
        });
        $owner = DB::table('roles')->where('name', 'owner')->value('id');
        foreach (['customers.read', 'customers.write'] as $name) {
            $id = DB::table('permissions')->insertGetId(['name' => $name]);
            DB::table('permission_role')->insert(['role_id' => $owner, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_events');
        Schema::dropIfExists('customers');
        DB::table('permissions')->whereIn('name', ['customers.read', 'customers.write'])->delete();
    }
};
