<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'system_admin')->value('id');
        foreach (['platform.audit', 'platform.health'] as $name) {
            $id = DB::table('permissions')->insertGetId(['name' => $name]);
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['platform.audit', 'platform.health'])->delete();
    }
};
