<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->foreign(['shop_id', 'customer_id'])->references(['shop_id', 'id'])->on('customers')->restrictOnDelete();
            $t->string('title', 150);
            $t->text('notes')->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('timezone', 80);
            $t->string('status', 20)->default('scheduled');
            $t->unsignedInteger('version')->default(1);
            $t->uuid('creation_key');
            $t->char('creation_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['shop_id', 'creation_key']);
            $t->unique(['shop_id', 'id']);
            $t->index(['shop_id', 'status', 'starts_at', 'ends_at']);
        });
        Schema::create('appointment_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('appointment_id');
            $t->foreign(['shop_id', 'appointment_id'])->references(['shop_id', 'id'])->on('appointments')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->json('previous')->nullable();
            $t->json('current');
            $t->text('reason')->nullable();
            $t->boolean('overlap_confirmed')->default(false);
            $t->timestamp('created_at')->useCurrent();
        });
        $owner = DB::table('roles')->where('name', 'owner')->value('id');
        foreach (['agenda.read', 'agenda.write'] as $name) {
            $id = DB::table('permissions')->insertGetId(['name' => $name]);
            DB::table('permission_role')->insert(['role_id' => $owner, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_events');
        Schema::dropIfExists('appointments');
        DB::table('permissions')->whereIn('name', ['agenda.read', 'agenda.write'])->delete();
    }
};
