<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vista_connections', function (Blueprint $table) {
            $table->timestamp('next_sync_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('vista_connections', fn (Blueprint $table) => $table->dropColumn('next_sync_at'));
    }
};
