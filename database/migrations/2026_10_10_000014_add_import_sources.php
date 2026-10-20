<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_imports', function (Blueprint $t) {
            $t->unsignedBigInteger('file_id')->nullable()->change();
            $t->string('source_kind')->default('file');
            $t->unsignedBigInteger('vista_connection_id')->nullable();
            $t->foreign(['shop_id', 'vista_connection_id'])->references(['shop_id', 'id'])->on('vista_connections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_imports', function (Blueprint $t) {
            $t->dropForeign(['shop_id', 'vista_connection_id']);
            $t->dropColumn('vista_connection_id');
            $t->dropColumn('source_kind');
        });
    }
};
