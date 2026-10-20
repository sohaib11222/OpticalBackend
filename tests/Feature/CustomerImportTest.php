<?php

namespace Tests\Feature;

use App\Jobs\Middleware\WithTenant;
use App\Jobs\ProcessCustomerImport;
use App\Jobs\ScanPrivateFile;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\User;
use App\Services\Files\FileScanner;
use App\Services\Imports\CustomerImports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('private');
        $this->owner = $this->owner('import@example.test');
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

    private function upload(string $bytes, string $name = 'customers.csv', bool $scan = true): int
    {
        $id = $this->postJson('/api/v1/documents', ['file' => UploadedFile::fake()->createWithContent($name, $bytes), 'title' => 'Import source', 'category' => 'document', 'upload_key' => (string) Str::uuid()])->assertStatus(202)->json('data.id');
        if ($scan) {
            $scanner = new class implements FileScanner
            {
                public function scan($stream): bool
                {
                    return true;
                }
            };
            $job = new ScanPrivateFile($this->owner->memberships()->first()->shop_id, $this->owner->id, $id);
            (new WithTenant($job->shopId, $job->actorId))->handle($job, fn ($j) => $j->handle($scanner));
        }

        return $id;
    }

    private function job(array $batch): void
    {
        $job = new ProcessCustomerImport($this->owner->memberships()->first()->shop_id, $this->owner->id, $batch['id'], $batch['version']);
        (new WithTenant($job->shopId, $job->actorId))->handle($job, fn ($j) => $j->handle(app(CustomerImports::class)));
    }

    private function batch(int $file): array
    {
        $b = $this->postJson('/api/v1/customer-imports', ['file_id' => $file, 'delimiter' => ','])->assertStatus(202)->json('data');
        $this->job($b);

        return $this->getJson('/api/v1/customer-imports/'.$b['id'])->assertOk()->json('data');
    }

    private function preview(array $b, array $map, array $extra = []): array
    {
        $queued = $this->patchJson('/api/v1/customer-imports/'.$b['id'].'/mapping', ['version' => $b['version'], 'mapping' => $map, 'date_format' => $extra['date_format'] ?? 'Y-m-d'])->assertStatus(202)->json('data');
        $this->job($queued);

        return $this->getJson('/api/v1/customer-imports/'.$b['id'])->json('data');
    }

    private function rows(array $b): array
    {
        return $this->getJson('/api/v1/customer-imports/'.$b['id'].'/rows')->assertOk()->json('data');
    }

    private function decide(array $b, array $rows): array
    {
        return $this->patchJson('/api/v1/customer-imports/'.$b['id'].'/decisions', ['version' => $b['version'], 'rows' => $rows])->assertOk()->json('data');
    }

    public function test_csv_mapping_validation_duplicate_review_import_history_and_idempotent_retries(): void
    {
        $file = $this->upload("First,Last,Email,Birth,CAP\nAda,Import,ada@example.test,04/03/1990,00123\nAda,Distinct,ada@example.test,05/03/1991,00124\n,Broken,bad-email,31/02/2000,00125\n");
        $b = $this->batch($file);
        $this->assertSame('mapping', $b['status']);
        $b = $this->preview($b, ['first_name' => 0, 'last_name' => 1, 'email' => 2, 'date_of_birth' => 3, 'postal_code' => 4], ['date_format' => 'd/m/Y']);
        $rows = $this->rows($b);
        $this->assertTrue($rows[0]['file_duplicate']);
        $this->assertSame('00123', $rows[0]['payload']['postal_code']);
        $this->assertNotEmpty($rows[2]['errors']);
        $this->postJson('/api/v1/customer-imports/'.$b['id'].'/commit', ['version' => $b['version']])->assertUnprocessable();
        $this->patchJson('/api/v1/customer-imports/'.$b['id'].'/decisions', ['version' => $b['version'], 'rows' => [['id' => $rows[0]['id'], 'decision' => 'create']]])->assertUnprocessable();
        $b = $this->decide($b, [['id' => $rows[0]['id'], 'decision' => 'create_distinct', 'reason' => 'Client confirmed separate customer'], ['id' => $rows[1]['id'], 'decision' => 'create_distinct', 'reason' => 'Shared family email'], ['id' => $rows[2]['id'], 'decision' => 'skip']]);
        $queued = $this->postJson('/api/v1/customer-imports/'.$b['id'].'/commit', ['version' => $b['version']])->assertStatus(202)->json('data');
        $this->job($queued);
        $this->job($queued);
        $this->getJson('/api/v1/customer-imports/'.$b['id'])->assertJsonPath('data.status', 'completed')->assertJsonPath('counts.created', 2)->assertJsonPath('counts.skipped', 1);
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseHas('customers', ['postal_code' => '00123', 'date_of_birth' => '1990-03-04', 'source' => 'file_import']);
        $this->postJson('/api/v1/customer-imports', ['file_id' => $file, 'delimiter' => ','])->assertOk()->assertJsonPath('data.id', $b['id']);
        $customer = $this->rows($b)[0]['result_customer_id'];
        $this->getJson('/api/v1/customers/'.$customer.'/history')->assertJsonPath('data.0.action', 'customer.imported');
        $this->patchJson('/api/v1/customer-imports/'.$b['id'].'/decisions', ['version' => $b['version'], 'rows' => [['id' => $rows[0]['id'], 'decision' => 'skip']]])->assertConflict();
    }

    public function test_explicit_updates_preserve_blanks_and_stale_targets_require_review(): void
    {
        $c = $this->postJson('/api/v1/customers', ['first_name' => 'Old', 'last_name' => 'Name', 'email' => 'match@example.test', 'city' => 'Preserved', 'creation_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $b = $this->preview($this->batch($this->upload("First,Last,Email,City\nNew,Name,match@example.test,\n")), ['first_name' => 0, 'last_name' => 1, 'email' => 2, 'city' => 3]);
        $row = $this->rows($b)[0];
        $b = $this->decide($b, [['id' => $row['id'], 'decision' => 'update', 'target_customer_id' => $c['id'], 'target_version' => $c['version'], 'reason' => 'Reviewed identity and changed first name']]);
        $this->patchJson('/api/v1/customers/'.$c['id'], array_merge($c, ['city' => 'Changed concurrently', 'confirm_distinct' => true]))->assertUnprocessable(); // protected API fields are not accepted
        $this->patchJson('/api/v1/customers/'.$c['id'], ['first_name' => 'Old', 'last_name' => 'Name', 'email' => 'match@example.test', 'city' => 'Concurrent', 'version' => 1])->assertOk();
        $queued = $this->postJson('/api/v1/customer-imports/'.$b['id'].'/commit', ['version' => $b['version']])->assertStatus(202)->json('data');
        $this->job($queued);
        $b = $this->getJson('/api/v1/customer-imports/'.$b['id'])->assertJsonPath('data.status', 'completed_with_errors')->json('data');
        $row = $this->rows($b)[0];
        $this->assertSame('error', $row['status']);
        $this->assertSame(2, $row['matches'][0]['version']);
        $b = $this->decide($b, [['id' => $row['id'], 'decision' => 'update', 'target_customer_id' => $c['id'], 'target_version' => 2, 'reason' => 'Reviewed latest version']]);
        $queued = $this->postJson('/api/v1/customer-imports/'.$b['id'].'/commit', ['version' => $b['version']])->assertStatus(202)->json('data');
        $this->job($queued);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', ['first_name' => 'New', 'city' => 'Concurrent', 'version' => 3, 'source' => 'manual']);
    }

    public function test_real_xlsx_preserves_text_postcodes_converts_explicit_excel_dates_and_never_evaluates_formulas(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Customers');
        $sheet->fromArray([['First', 'Last', 'Birth', 'CAP'], ['Excel', 'Import', 32874, '00123'], ['Formula', 'Unsafe', 32874, '=HYPERLINK("https://example.test","click")']]);
        $sheet->setCellValueExplicit('D2', '00123', DataType::TYPE_STRING);
        $path = Storage::disk('private')->path('fixture.xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $b = $this->preview($this->batch($this->upload(file_get_contents($path), 'customers.xlsx')), ['first_name' => 0, 'last_name' => 1, 'date_of_birth' => 2, 'postal_code' => 3], ['date_format' => 'excel']);
        $rows = $this->rows($b);
        $this->assertSame('00123', $rows[0]['payload']['postal_code']);
        $this->assertSame('1990-01-01', $rows[0]['payload']['date_of_birth']);
        $this->assertNotEmpty($rows[1]['errors']);
        $this->assertStringContainsString('formula', implode(' ', $rows[1]['errors']));
    }

    public function test_scan_tenant_permission_and_job_access_boundaries(): void
    {
        $file = $this->upload("First,Last\nSecure,Person\n", 'customers.csv', false);
        $this->postJson('/api/v1/customer-imports', ['file_id' => $file, 'delimiter' => ','])->assertConflict();
        $b = $this->batch($this->upload("First,Last\nSecure,Person\n"));
        $other = $this->owner('otherimport@example.test');
        $this->login($other);
        $this->getJson('/api/v1/customer-imports/'.$b['id'])->assertNotFound();
        $this->getJson('/api/v1/customer-imports/'.$b['id'].'/rows')->assertNotFound();
        $this->postJson('/api/v1/customer-imports', ['file_id' => $file, 'delimiter' => ','])->assertNotFound();
        $this->getJson('/api/v1/customer-imports')->assertJsonCount(0, 'data');
        $this->login($this->owner);
        DB::table('shops')->where('id', $this->owner->memberships()->first()->shop_id)->update(['status' => 'suspended']);
        try {
            $this->job($b);
            $this->fail('Revoked job must not run.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Tenant job access revoked.', $e->getMessage());
        }
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_source_integrity_parser_bounds_and_stale_mapping_jobs_fail_safely(): void
    {
        $file = $this->upload("First,Last,CAP\nLiteral,Zero,0\n");
        $b = $this->batch($file);
        $old = $this->patchJson('/api/v1/customer-imports/'.$b['id'].'/mapping', ['version' => $b['version'], 'mapping' => ['first_name' => 0, 'last_name' => 1, 'postal_code' => 2], 'date_format' => 'Y-m-d'])->assertStatus(202)->json('data');
        $this->patchJson('/api/v1/customer-imports/'.$b['id'].'/mapping', ['version' => $b['version'], 'mapping' => ['first_name' => 0, 'last_name' => 1], 'date_format' => 'Y-m-d'])->assertConflict();
        $this->job($old);
        $this->job($old);
        $this->assertSame('0', $this->rows($b)[0]['payload']['postal_code']);
        $file = $this->upload("First,Last\n".str_repeat("Too,Many\n", 5001));
        $queued = $this->postJson('/api/v1/customer-imports', ['file_id' => $file, 'delimiter' => ','])->assertStatus(202)->json('data');
        try {
            $this->job($queued);
            $this->fail('Oversized row count must fail.');
        } catch (ValidationException) {
        }
        $this->getJson('/api/v1/customer-imports/'.$queued['id'])->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('customers', 0);
        $this->getJson('/api/v1/documents/'.$file)->assertOk();
        $key = DB::table('private_files')->where('id', $file)->value('storage_key');
        Storage::disk('private')->put($key, "First,Last\nTampered,File");
        try {
            $this->job($queued);
            $this->fail('Tampered file must fail.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('customers', 0);
    }
}
