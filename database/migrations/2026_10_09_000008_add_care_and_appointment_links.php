<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optical_records', function (Blueprint $t) {
            $t->unsignedBigInteger('source_record_id')->nullable();
            $t->foreign(['shop_id', 'customer_id', 'source_record_id'], 'optical_source_same_customer')->references(['shop_id', 'customer_id', 'id'])->on('optical_records')->restrictOnDelete();
        });
        Schema::table('appointments', function (Blueprint $t) {
            $t->unsignedBigInteger('optical_record_id')->nullable();
            $t->foreign(['shop_id', 'customer_id', 'optical_record_id'], 'appointment_optical_same_customer')->references(['shop_id', 'customer_id', 'id'])->on('optical_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->dropForeign('appointment_optical_same_customer');
            $t->dropColumn('optical_record_id');
        });
        Schema::table('optical_records', function (Blueprint $t) {
            $t->dropForeign('optical_source_same_customer');
            $t->dropColumn('source_record_id');
        });
    }
};
