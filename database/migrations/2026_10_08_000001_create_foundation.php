<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('account_type')->default('shop');
            $t->string('status')->default('active');
            $t->unsignedInteger('auth_version')->default(1);
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('status')->default('pending')->index();
            $t->timestamps();
        });
        Schema::create('shop_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->unique(['shop_id', 'user_id']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action')->index();
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->uuid('request_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('private_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->string('storage_key')->unique();
            $t->string('filename');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('scan_status')->default('pending');
            $t->timestamps();
            $t->index(['shop_id', 'created_at']);
        });
        foreach (['owner' => ['shop.view', 'shop.update', 'members.view', 'files.read'], 'system_admin' => ['platform.view', 'shops.view', 'shops.status']] as $role => $permissions) {
            $rid = DB::table('roles')->insertGetId(['name' => $role]);
            foreach ($permissions as $permission) {
                $pid = DB::table('permissions')->insertGetId(['name' => $permission]);
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
    }

    public function down(): void
    {
        foreach (['private_files', 'audit_events', 'shop_memberships', 'shops', 'permission_role', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['account_type', 'status', 'auth_version']));
    }
};
