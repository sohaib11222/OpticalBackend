<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optical_records', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('customer_id');
            $t->foreign(['shop_id', 'customer_id'])->references(['shop_id', 'id'])->on('customers')->restrictOnDelete();
            $t->string('kind', 30);
            $t->string('status', 20)->default('draft');
            $t->date('recorded_on');
            $t->string('practitioner', 150);
            $t->json('data');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('amends_id')->nullable();
            $t->text('amendment_reason')->nullable();
            $t->unsignedBigInteger('prescription_id')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('finalized_at')->nullable();
            $t->uuid('creation_key');
            $t->char('creation_hash', 64);
            $t->timestamps();
            $t->unique(['shop_id', 'id']);
            $t->unique(['shop_id', 'customer_id', 'id']);
            $t->unique(['shop_id', 'creation_key']);
            $t->unique(['shop_id', 'amends_id']);
            $t->foreign(['shop_id', 'customer_id', 'amends_id'])->references(['shop_id', 'customer_id', 'id'])->on('optical_records')->restrictOnDelete();
            $t->foreign(['shop_id', 'customer_id', 'prescription_id'])->references(['shop_id', 'customer_id', 'id'])->on('optical_records')->restrictOnDelete();
            $t->index(['shop_id', 'customer_id', 'kind', 'recorded_on']);
        });
        $owner = DB::table('roles')->where('name', 'owner')->value('id');
        foreach (['optical.read', 'optical.write', 'optical.finalize'] as $name) {
            $id = DB::table('permissions')->insertGetId(['name' => $name]);
            DB::table('permission_role')->insert(['role_id' => $owner, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_records');
        DB::table('permissions')->whereIn('name', ['optical.read', 'optical.write', 'optical.finalize'])->delete();
    }
};
