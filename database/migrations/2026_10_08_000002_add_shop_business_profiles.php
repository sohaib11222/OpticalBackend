<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $t) {
            $t->string('phone', 40)->nullable();
            $t->string('address_line1')->nullable();
            $t->string('address_line2')->nullable();
            $t->string('city', 100)->nullable();
            $t->string('postal_code', 32)->nullable();
            $t->string('country', 100)->nullable();
            $t->string('timezone', 80)->default('UTC');
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn(['phone', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'timezone']));
    }
};
