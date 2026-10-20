<?php

use App\Services\AccessCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_memberships', function (Blueprint $t) {
            $t->json('permission_overrides')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('invitation_hash', 64)->nullable()->unique();
            $t->timestamp('invitation_expires_at')->nullable();
            $t->timestamp('invitation_accepted_at')->nullable();
        });
        AccessCatalog::install();
    }

    public function down(): void
    {
        Schema::table('shop_memberships', fn (Blueprint $t) => $t->dropColumn(['permission_overrides', 'version', 'invitation_hash', 'invitation_expires_at', 'invitation_accepted_at']));
    }
};
