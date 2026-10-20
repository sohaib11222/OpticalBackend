<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $admin = DB::table('roles')->where('name', 'system_admin')->value('id');
        $permissions = DB::table('permissions')->whereNotIn('name', ['platform.view', 'platform.audit', 'platform.health', 'shops.view', 'shops.status'])->pluck('name');
        foreach (['shops.workspace', ...$permissions->map(fn ($p) => 'shops.'.$p)->all()] as $name) {
            $id = DB::table('permissions')->insertGetId(['name' => $name]);
            if ($admin) {
                DB::table('permission_role')->insert(['role_id' => $admin, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'shops.workspace')->orWhere('name', 'like', 'shops.%.%')->delete();
    }
};
