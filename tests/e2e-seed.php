<?php

use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_ends_with(config('database.connections.sqlite.database'), 'e2e.sqlite')) {
    throw new RuntimeException('E2E seed requires isolated e2e.sqlite');
}
foreach (['admin', 'adminrecovery', 'owner', 'other', 'security', 'adminsecurity', 'workflow', 'isolated', 'workspace', 'extension'] as $kind) {
    $u = User::factory()->create(['name' => ucfirst($kind).' Test', 'email' => $kind.'@example.test', 'password' => 'TestingPassword123!', 'account_type' => str_starts_with($kind, 'admin') ? 'admin' : 'shop']);
    if (! config('optical.email_verification_required')) {
        $u->forceFill(['email_verified_at' => null])->save();
    }
    if (! str_starts_with($kind, 'admin')) {
        $s = new Shop(['name' => ucfirst($kind).' Optical']);
        $s->status = 'active';
        $s->save();
        ShopMembership::create(['user_id' => $u->id, 'shop_id' => $s->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
    }
}
