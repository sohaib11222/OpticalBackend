<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL auto-commits DDL. The former generated index name could fail
        // after the table itself had been created, so tolerate that precise
        // partial-migration state on a deployment and finish the short index.
        if (! Schema::hasTable('account_password_change_codes')) {
            Schema::create('account_password_change_codes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('session_hash', 64);
                $table->string('code_hash');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('account_password_change_codes', function (Blueprint $table) {
            // MySQL identifiers are limited to 64 characters. Do not let the
            // schema builder generate an index name from this long table name.
            $table->index(['user_id', 'session_hash', 'used_at', 'expires_at'], 'acct_pwd_code_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_password_change_codes');
    }
};
