<?php

namespace App\Services\Imports;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Models\CustomerImport;
use App\Models\CustomerImportRow;
use App\Models\PrivateFile;
use App\Models\Shop;
use App\Models\VistaConnection;
use App\Services\Audit;
use App\Services\Commerce\Notices;
use App\Services\CustomerRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CustomerImports
{
    private function sourceAvailable(CustomerImport $batch): void
    {
        if ($batch->source_kind !== 'vista_express') {
            $this->file($batch->file_id);

            return;
        }
        $connection = VistaConnection::findOrFail($batch->vista_connection_id);
        abort_if($connection->status === 'disconnected' || ! $connection->access_token || $connection->expires_at->isPast(), 409, 'Reconnect Vista Express before importing its contacts.');
    }

    public function start(array $d, int $actor): CustomerImport
    {
        return DB::transaction(function () use ($d, $actor) {
            Shop::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $file = $this->file($d['file_id']);
            $hash = hash('sha256', $file->sha256.'|'.($d['sheet_name'] ?? '').'|'.$d['delimiter']);

            return CustomerImport::firstOrCreate(['source_hash' => $hash], ['file_id' => $file->id, 'sheet_name' => $d['sheet_name'] ?? null, 'delimiter' => $d['delimiter'], 'created_by' => $actor])->refresh();
        });
    }

    private function file(int $id): PrivateFile
    {
        $file = PrivateFile::findOrFail($id);
        abort_unless($file->scan_status === 'clean' && ! $file->archived_at, 409, 'The source file must pass its security scan and remain available.');
        abort_unless(in_array(strtolower(pathinfo($file->filename, PATHINFO_EXTENSION)), ['csv', 'xlsx']), 422, 'Select a CSV or XLSX customer file.');
        abort_unless(str_starts_with($file->storage_key, 'shops/'.app(TenantContext::class)->id().'/') && ! str_contains($file->storage_key, '..'), 404);

        return $file;
    }

    public function parse(CustomerImport $batch): void
    {
        $file = $this->file($batch->file_id);
        $path = Storage::disk('private')->path($file->storage_key);
        abort_unless(hash_equals($file->sha256, hash_file('sha256', $path)), 409, 'Source file integrity check failed.');
        $parsed = app(CustomerFileReader::class)->read($path, strtolower(pathinfo($file->filename, PATHINFO_EXTENSION)), $batch->sheet_name, $batch->delimiter);
        DB::transaction(function () use ($batch, $parsed) {
            $batch->rows()->delete();
            foreach (array_chunk($parsed['rows'], 200) as $chunk) {
                $records = array_map(fn ($r) => ['shop_id' => $batch->shop_id, 'customer_import_id' => $batch->id, 'row_number' => $r['row_number'], 'creation_key' => (string) Str::uuid(), 'raw_values' => json_encode($r['values'], JSON_THROW_ON_ERROR), 'parse_errors' => json_encode($r['errors'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()], $chunk);
                CustomerImportRow::insert($records);
            }
            $batch->fill(array_intersect_key($parsed, array_flip(['headers', 'sheets', 'sheet_name', 'date_system'])));
            $batch->total_rows = count($parsed['rows']);
            $batch->status = 'mapping';
            $batch->last_error = null;
            $batch->version++;
            $batch->save();
        });
    }

    public function configure(int $id, array $d, string $stage): CustomerImport
    {
        return DB::transaction(function () use ($id, $d, $stage) {
            $b = CustomerImport::lockForUpdate()->findOrFail($id);
            abort_unless($b->version === $d['version'], 409, 'Import changed. Refresh and review again.');
            abort_unless(in_array($b->status, ['mapping', 'ready', 'failed']) && $b->processed_rows === 0, 409, 'This import can no longer be remapped.');
            if ($stage === 'parse') {
                abort_if($b->source_kind === 'vista_express', 422, 'Vista imports have no file sheet or delimiter to reparse.');
                $b->sheet_name = $d['sheet_name'] ?? $b->sheet_name;
                $b->delimiter = $d['delimiter'] ?? $b->delimiter;
            } else {
                $indices = array_values($d['mapping']);
                abort_unless(count($indices) === count(array_unique($indices)), 422, 'Map each source column only once.');
                foreach ($indices as $index) {
                    abort_unless($index < count($b->headers), 422, 'A mapped column does not exist.');
                }
                $b->mapping = $d['mapping'];
                $b->date_format = $d['date_format'];
            }
            $b->stage = $stage;
            $b->status = $stage === 'parse' ? 'parsing' : 'previewing';
            $b->last_error = null;
            $b->version++;
            $b->save();

            return $b;
        });
    }

    private function payload(CustomerImport $b, CustomerImportRow $r): array
    {
        $data = array_fill_keys(Customer::FIELDS, null);
        $errors = $r->parse_errors ?? [];
        foreach ($b->mapping as $field => $index) {
            $value = trim($r->raw_values[$index] ?? '');
            $data[$field] = $value === '' ? null : $value;
        }
        if ($data['email']) {
            $data['email'] = strtolower($data['email']);
        }
        if ($data['date_of_birth']) {
            $value = $data['date_of_birth'];
            try {
                if ($b->date_format === 'excel') {
                    if (! is_numeric($value) || (float) $value < 1 || (float) $value > 2958465) {
                        throw new \RuntimeException;
                    }
                    $previous = Date::getExcelCalendar();
                    try {
                        Date::setExcelCalendar($b->date_system);
                        $data['date_of_birth'] = Date::excelToDateTimeObject((float) $value, new \DateTimeZone('UTC'))->format('Y-m-d');
                    } finally {
                        Date::setExcelCalendar($previous);
                    }
                } else {
                    $date = \DateTimeImmutable::createFromFormat('!'.$b->date_format, $value);
                    if (! $date || $date->format($b->date_format) !== $value) {
                        throw new \RuntimeException;
                    }
                    $data['date_of_birth'] = $date->format('Y-m-d');
                }
            } catch (\Throwable) {
                $errors[] = 'Date of birth does not match the selected date format.';
            }
        }
        $v = Validator::make($data, CustomerRequest::fieldRules());
        $errors = array_merge($errors, $v->errors()->all());

        return [$data, array_values(array_unique($errors))];
    }

    private function keys(array $d): array
    {
        $keys = [];
        if ($d['email']) {
            $keys[] = 'email:'.mb_strtolower($d['email']);
        }
        if ($d['phone']) {
            $keys[] = 'phone:'.preg_replace('/\D/', '', $d['phone']);
        }
        if ($d['date_of_birth']) {
            $keys[] = 'name:'.mb_strtolower($d['first_name'].'|'.$d['last_name']).'|'.$d['date_of_birth'];
        }

        return $keys;
    }

    public function preview(CustomerImport $b): void
    {
        $counts = [];
        $registry = app(CustomerRegistry::class);
        foreach ($b->rows()->orderBy('id')->cursor() as $r) {
            [$data,$errors] = $this->payload($b, $r);
            $r->payload = $data;
            $r->errors = $errors;
            $r->matches = $errors ? [] : $registry->duplicates($data)->map->only(['id', 'first_name', 'last_name', 'email', 'phone', 'status', 'version'])->all();
            $r->decision = 'pending';
            $r->status = 'pending';
            $r->result_error = null;
            $r->save();
            foreach ($this->keys($data) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        foreach ($b->rows()->cursor() as $r) {
            $r->file_duplicate = count(array_filter($this->keys($r->payload), fn ($k) => $counts[$k] > 1)) > 0;
            $r->save();
        }
        $b->status = 'ready';
        $b->last_error = null;
        $b->version++;
        $b->save();
    }

    public function decide(int $id, array $d): CustomerImport
    {
        return DB::transaction(function () use ($id, $d) {
            $b = CustomerImport::lockForUpdate()->findOrFail($id);
            abort_unless($b->version === $d['version'], 409, 'Import changed. Refresh and review again.');
            abort_unless(in_array($b->status, ['ready', 'completed_with_errors']), 409, 'Wait until preview is ready.');
            foreach ($d['rows'] as $choice) {
                $r = $b->rows()->lockForUpdate()->findOrFail($choice['id']);
                abort_if(in_array($r->status, ['created', 'updated', 'skipped']), 409, 'Processed rows cannot be changed.');
                $action = $choice['decision'];
                $reason = $choice['reason'] ?? null;
                abort_if($action !== 'skip' && count($r->errors ?? []) > 0, 422, 'Correct the file or skip invalid rows.');
                abort_if($action === 'create' && ($r->matches || $r->file_duplicate), 422, 'Review duplicates and choose a distinct customer, update, or skip.');
                abort_if(in_array($action, ['create_distinct', 'update']) && mb_strlen(trim($reason ?? '')) < 3, 422, 'Explain the duplicate or update decision.');
                $target = null;
                if ($action === 'update') {
                    $target = Customer::findOrFail($choice['target_customer_id'] ?? 0);
                    abort_unless($target->status === 'active', 409, 'Restore the selected customer before importing changes.');
                    abort_unless($target->version === ($choice['target_version'] ?? null), 409, 'The target customer changed. Refresh its details before deciding.');
                    abort_unless(in_array($target->id, app(CustomerRegistry::class)->duplicates($r->payload)->pluck('id')->all()), 422, 'Choose a matching customer from the preview.');
                }
                $r->fill(['decision' => $action, 'target_customer_id' => $target?->id, 'target_version' => $target?->version, 'overwrite_blanks' => $choice['overwrite_blanks'] ?? false, 'reason' => $reason, 'status' => 'pending', 'result_error' => null]);
                $r->save();
            }
            $b->status = 'ready';
            $b->version++;
            $b->save();

            return $b;
        });
    }

    public function commit(int $id, array $d): CustomerImport
    {
        return DB::transaction(function () use ($id, $d) {
            $b = CustomerImport::lockForUpdate()->findOrFail($id);
            abort_unless($b->version === $d['version'], 409, 'Import changed. Refresh before importing.');
            abort_unless($b->status === 'ready', 409, 'Complete preview and decisions first.');
            abort_if($b->rows()->where('decision', 'pending')->exists(), 422, 'Make a decision for every row before importing.');
            $this->sourceAvailable($b);
            $b->stage = 'commit';
            $b->status = 'committing';
            $b->version++;
            $b->last_error = null;
            $b->save();

            return $b;
        });
    }

    public function process(CustomerImport $b, int $actor): void
    {
        $registry = app(CustomerRegistry::class);
        foreach ($b->rows()->whereIn('status', ['pending', 'error'])->orderBy('id')->pluck('id') as $rowId) {
            // A long-running batch must stop if the actor or shop loses access.
            $membership = app(TenantContext::class)->membership()->fresh(['shop', 'user', 'role.permissions']);
            abort_unless($membership && $membership->status === 'active' && $membership->shop->status === 'active' && $membership->user->status === 'active' && $membership->user->canUseVerifiedFeatures() && $membership->can('imports.write') && $membership->can('customers.write'), 403);
            $this->sourceAvailable($b);
            try {
                DB::transaction(function () use ($b, $rowId, $registry, $actor, $membership) {
                    Shop::whereKey($b->shop_id)->lockForUpdate()->firstOrFail();
                    $r = CustomerImportRow::lockForUpdate()->findOrFail($rowId);
                    if (in_array($r->status, ['created', 'updated', 'skipped'])) {
                        return;
                    }
                    if ($r->decision === 'skip') {
                        $r->status = 'skipped';
                        $r->save();

                        return;
                    }
                    abort_unless($membership->can($r->decision === 'update' ? 'customers.edit' : 'customers.create'), 403, 'This customer operation is not included in your permissions.');
                    $data = $r->payload;
                    $target = $r->decision === 'update' ? Customer::findOrFail($r->target_customer_id) : null;
                    if ($target) {
                        $data = $target->only(Customer::FIELDS);
                        foreach ($b->mapping as $field => $column) {
                            if ($r->payload[$field] !== null || $r->overwrite_blanks) {
                                $data[$field] = $r->payload[$field];
                            }
                        }
                    }
                    Validator::make($data, CustomerRequest::fieldRules())->validate();
                    [$customer] = $registry->save($data + ['creation_key' => $r->creation_key, 'version' => $r->target_version, 'confirm_distinct' => $r->decision === 'create_distinct' || $r->decision === 'update'], $actor, $target?->id);
                    if (! $target) {
                        $customer->source = $b->source_kind === 'vista_express' ? 'vista_express' : 'file_import';
                        $customer->save();
                    }
                    $registry->event($customer, $target ? 'customer.import_updated' : 'customer.imported', $actor, array_keys($b->mapping), $r->reason, 'customer_import', $b->id);
                    $r->result_customer_id = $customer->id;
                    $r->status = $target ? 'updated' : 'created';
                    $r->result_error = null;
                    $r->save();
                }, 3);
            } catch (ValidationException $e) {
                CustomerImportRow::whereKey($rowId)->update(['status' => 'error', 'result_error' => implode(' ', array_merge(...array_values($e->errors())))]);
            } catch (HttpExceptionInterface $e) {
                CustomerImportRow::whereKey($rowId)->update(['status' => 'error', 'result_error' => $e->getStatusCode() === 404 ? 'The linked customer is no longer available.' : $e->getMessage()]);
            }
            // Unexpected database/infrastructure failures are retried by the queue, never marked successful.
            $b->processed_rows = $b->rows()->whereIn('status', ['created', 'updated', 'skipped'])->count();
            $b->save();
        }
        $b->status = $b->rows()->where('status', 'error')->exists() ? 'completed_with_errors' : 'completed';
        $b->version++;
        $b->save();
        app(Notices::class)->emit('imports', 'imports.read', 'import:'.$b->id.':'.$b->version, 'import.'.$b->status, 'Customer import #'.$b->id.' '.str_replace('_', ' ', $b->status), '/customer-imports/'.$b->id);
        Audit::record('customer_import.'.$b->status, $b->shop_id, $actor, 'customer_import', $b->id);
    }
}
