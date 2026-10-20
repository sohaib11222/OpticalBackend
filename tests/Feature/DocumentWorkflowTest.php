<?php

namespace Tests\Feature;

use App\Jobs\Middleware\WithTenant;
use App\Jobs\ScanPrivateFile;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Files\FileScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('private');
        $this->owner = $this->owner('documents@example.test');
        $this->login($this->owner);
    }

    private function owner(string $email): User
    {
        $u = User::factory()->create(['email' => $email, 'password' => 'TestingPassword123!']);
        $s = new Shop(['name' => $email]);
        $s->status = 'active';
        $s->save();
        ShopMembership::create(['shop_id' => $s->id, 'user_id' => $u->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

        return $u;
    }

    private function login(User $u): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'TestingPassword123!'])->assertOk();
        Auth::forgetGuards();
    }

    private function upload(array $extra = [])
    {
        return $this->postJson('/api/v1/documents', array_merge(['file' => UploadedFile::fake()->createWithContent('visit.txt', 'Synthetic private visit document'), 'title' => 'Visit document', 'category' => 'document', 'upload_key' => (string) Str::uuid()], $extra));
    }

    private function scan(int $id, bool $clean = true, bool $fail = false): void
    {
        $scanner = new class($clean, $fail) implements FileScanner
        {
            public function __construct(private bool $clean, private bool $fail) {}

            public function scan($stream): bool
            {
                if ($this->fail) {
                    throw new \RuntimeException('Synthetic scanner outage');
                }

                return $this->clean;
            }
        };
        $job = new ScanPrivateFile($this->owner->memberships()->first()->shop_id, $this->owner->id, $id);
        (new WithTenant($job->shopId, $job->actorId))->handle($job, fn ($j) => $j->handle($scanner));
    }

    public function test_private_upload_is_quarantined_then_scanned_downloadable_and_archivable(): void
    {
        $customer = $this->postJson('/api/v1/customers', ['first_name' => 'Document', 'last_name' => 'Customer', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $r = $this->upload(['customer_id' => $customer])->assertStatus(202)->assertJsonPath('data.scan_status', 'pending');
        $id = $r->json('data.id');
        $this->assertArrayNotHasKey('storage_key', $r->json('data'));
        Queue::assertPushed(ScanPrivateFile::class);
        $this->getJson('/api/v1/private-files/'.$id)->assertConflict();
        $this->scan($id);
        $this->getJson('/api/v1/documents/'.$id)->assertOk()->assertJsonPath('data.scan_status', 'clean');
        $this->get('/api/v1/private-files/'.$id)->assertOk()->assertDownload('visit.txt');
        $this->patchJson('/api/v1/documents/'.$id.'/archive', ['version' => 1, 'archived' => true, 'reason' => 'Replaced document'])->assertOk();
        $this->getJson('/api/v1/private-files/'.$id)->assertConflict();
        $this->getJson('/api/v1/documents?customer_id='.$customer)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents?archived=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/customers/'.$customer.'/history')->assertJsonPath('data.0.action', 'document.archived');
    }

    public function test_failed_or_rejected_scans_never_release_private_files(): void
    {
        $id = $this->upload()->assertStatus(202)->json('data.id');
        try {
            $this->scan($id, true, true);
            $this->fail('Scanner failure should throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic scanner outage', $e->getMessage());
        }
        $this->getJson('/api/v1/documents/'.$id)->assertJsonPath('data.scan_status', 'failed');
        $this->getJson('/api/v1/private-files/'.$id)->assertConflict();
        $this->postJson('/api/v1/documents/'.$id.'/rescan')->assertStatus(202);
        $this->scan($id, false);
        $this->getJson('/api/v1/private-files/'.$id)->assertConflict();
        $this->getJson('/api/v1/documents/'.$id)->assertJsonPath('data.scan_status', 'rejected');
        $this->postJson('/api/v1/documents/'.$id.'/rescan')->assertConflict();
    }

    public function test_tenant_scope_image_validation_and_private_preview(): void
    {
        $this->upload(['file' => UploadedFile::fake()->createWithContent('fake.png', 'This is not an image')])->assertUnprocessable();
        $this->upload(['file' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;')])->assertUnprocessable();
        $this->upload(['category' => 'customer_image'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $id = $this->upload(['file' => UploadedFile::fake()->image('frame.png', 60, 60)])->assertStatus(202)->json('data.id');
        $this->scan($id);
        $this->get('/api/v1/documents/'.$id.'/preview')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->login($this->owner('foreign@example.test'));
        $this->getJson('/api/v1/documents')->assertJsonCount(0, 'data');
        foreach (['/api/v1/documents/'.$id, '/api/v1/documents/'.$id.'/preview', '/api/v1/private-files/'.$id] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->postJson('/api/v1/documents/'.$id.'/rescan')->assertNotFound();
        $this->patchJson('/api/v1/documents/'.$id.'/archive', ['version' => 1, 'archived' => true, 'reason' => 'Foreign attempt'])->assertNotFound();
    }

    public function test_upload_retries_do_not_duplicate_and_metadata_cannot_change_reused_key(): void
    {
        $key = (string) Str::uuid();
        $id = $this->upload(['upload_key' => $key])->assertStatus(202)->json('data.id');
        $this->upload(['upload_key' => $key])->assertStatus(202)->assertJsonPath('data.id', $id);
        $this->upload(['upload_key' => $key, 'title' => 'Different'])->assertConflict();
        $this->assertDatabaseCount('private_files', 1);
    }

    public function test_glasses_images_require_the_matching_customer_and_glasses_record(): void
    {
        $customer = $this->postJson('/api/v1/customers', ['first_name' => 'Glasses', 'last_name' => 'Customer', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $record = $this->postJson('/api/v1/customers/'.$customer.'/optical/glasses', ['recorded_on' => '2026-01-01', 'practitioner' => 'Synthetic Optician', 'data' => ['brand' => 'Test', 'model' => 'Frame', 'color' => 'Blue'], 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $file = $this->upload(['file' => UploadedFile::fake()->image('glasses.png', 60, 60), 'category' => 'glasses_image', 'customer_id' => $customer, 'optical_record_id' => $record])->assertStatus(202)->json('data.id');
        $this->scan($file);
        $this->getJson('/api/v1/documents?customer_id='.$customer.'&optical_record_id='.$record)->assertJsonCount(1, 'data');
        $other = $this->postJson('/api/v1/customers', ['first_name' => 'Other', 'last_name' => 'Customer', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->upload(['file' => UploadedFile::fake()->image('wrong.png', 60, 60), 'category' => 'glasses_image', 'customer_id' => $other, 'optical_record_id' => $record])->assertNotFound();
    }
}
