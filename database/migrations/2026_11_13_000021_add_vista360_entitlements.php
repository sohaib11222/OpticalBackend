<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vista_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('vista_connections', 'access_mode')) {
                // Existing standalone account connections are deliberately not
                // subscription gated. Only an OAuth login uses vista360 mode.
                $table->string('access_mode', 32)->default('integration')->after('provider');
                $table->index(['access_mode', 'status'], 'vista_connection_access_status');
            }
        });

        Schema::create('vista360_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('vista_connection_id')->unique()->constrained('vista_connections')->cascadeOnDelete();
            $table->string('subject', 100);
            $table->string('store_id', 100);
            $table->string('status', 32)->default('unknown');
            $table->json('plan')->nullable();
            $table->json('features')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('entitlement_version')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('last_remote_at')->nullable();
            $table->string('source_event_id', 100)->nullable()->unique();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'vista_connection_id'], 'vista360_entitlement_connection_shop');
            $table->index(['status', 'expires_at'], 'vista360_entitlement_status_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vista360_entitlements');
        Schema::table('vista_connections', function (Blueprint $table) {
            if (Schema::hasColumn('vista_connections', 'access_mode')) {
                $table->dropIndex('vista_connection_access_status');
                $table->dropColumn('access_mode');
            }
        });
    }
};
