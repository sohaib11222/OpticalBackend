<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Files\FileScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class NamedUploadQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_worker_must_consume_uploads_to_release_a_scanned_file(): void
    {
        config(['queue.default' => 'database']);
        Storage::fake('private');
        $this->app->bind(FileScanner::class, fn () => new class implements FileScanner {
            public function scan($stream): bool { return stream_get_contents($stream) === "First,Last\nQueue,Customer\n"; }
        });
        $user = User::factory()->create(['password' => 'TestingPassword123!']);
        $shop = new Shop(['name' => 'Queue test']);
        $shop->status = 'active'; $shop->save();
        ShopMembership::create(['shop_id' => $shop->id, 'user_id' => $user->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'TestingPassword123!'])->assertOk();
        $id = $this->postJson('/api/v1/documents', ['file' => UploadedFile::fake()->createWithContent('queue.csv', "First,Last\nQueue,Customer\n"), 'title' => 'Queue CSV', 'category' => 'document', 'upload_key' => (string) Str::uuid()])->assertStatus(202)->json('data.id');
        $this->assertDatabaseHas('jobs', ['queue' => 'uploads']);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0]);
        $this->getJson('/api/v1/documents/'.$id)->assertJsonPath('data.scan_status', 'pending');
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'uploads', '--once' => true, '--sleep' => 0]);
        $this->getJson('/api/v1/documents/'.$id)->assertJsonPath('data.scan_status', 'clean');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }
}
