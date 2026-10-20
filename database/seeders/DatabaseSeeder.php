<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles/permissions are migrated. Real administrators use optical:admin.
        // Synthetic identities are restricted to tests/e2e-seed.php.
    }
}
