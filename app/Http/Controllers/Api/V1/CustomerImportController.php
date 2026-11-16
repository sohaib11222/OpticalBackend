<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCustomerImport;
use App\Models\Customer;
use App\Models\CustomerImport;
use App\Services\CustomerRegistry;
use App\Services\Imports\CustomerImports;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerImportController extends Controller
{
    private function permit(string $action): void
    {
        $m = app(TenantContext::class)->membership();
        abort_unless($m->can('imports.'.$action) && $m->can('customers.'.$action), 403);
    }

    private function queued(CustomerImport $b, int $actor)
    {
        $data = $b->toArray();
        try {
            if (config('documents.customer_imports_sync')) {
                // The request already has a verified tenant context. Calling
                // the existing job handler directly avoids a queue driver's
                // after-commit semantics (and a delayed worker heartbeat)
                // while retaining its permission, version and idempotency
                // checks. Large-import installations can opt back into the
                // named uploads queue below.
                (new ProcessCustomerImport($b->shop_id, $actor, $b->id, $b->version))
                    ->handle(app(CustomerImports::class));
            } else {
                ProcessCustomerImport::dispatch($b->shop_id, $actor, $b->id, $b->version)->afterCommit();
            }
        } catch (\Throwable $e) {
            $b->forceFill(['status' => 'failed', 'last_error' => 'Processing could not be started. Check the upload worker and retry this import.'])->save();
            report($e);
        }
        $data = $b->fresh()->toArray();

        return response()->json(['data' => $data], config('documents.customer_imports_sync') ? 200 : 202);
    }

    public function index(Request $r)
    {
        $this->permit('read');
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return CustomerImport::with('file:id,shop_id,title,filename')->orderByDesc('id')->paginate(20);
    }

    public function show(int $id)
    {
        $this->permit('read');
        $b = CustomerImport::with('file:id,shop_id,title,filename')->findOrFail($id);

        $counts = $b->rows()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        // Keep summary portable across MySQL and the SQLite test database;
        // imports are capped at 5,000 rows so this small projection is safe.
        $rows = $b->rows()->get(['status', 'decision', 'file_duplicate', 'errors', 'matches']);
        $attention = $rows->filter(fn ($row) => $row->decision === 'pending' || count($row->errors ?? []) > 0)->count();

        return [
            'data' => $b,
            'counts' => $counts,
            'undecided' => $b->rows()->where('decision', 'pending')->count(),
            'summary' => [
                'total' => $b->total_rows,
                'valid' => $rows->where('decision', 'create')->where('status', 'pending')->count(),
                'duplicates' => $rows->filter(fn ($row) => $row->file_duplicate || count($row->matches ?? []) > 0)->count(),
                'requires_attention' => $attention,
                'imported' => (int) (($counts['created'] ?? 0) + ($counts['updated'] ?? 0)),
                'skipped' => (int) ($counts['skipped'] ?? 0),
                'failed' => (int) ($counts['error'] ?? 0),
            ],
        ];
    }

    public function rows(Request $r, int $id)
    {
        $this->permit('read');
        // Query parameters arrive as strings. Accept the conventional
        // `?attention=true`/`?undecided=false` spellings as well as 1/0,
        // while leaving genuinely invalid input for the validator to reject.
        $normalized = [];
        foreach (['undecided', 'attention'] as $key) {
            if (! $r->has($key)) {
                continue;
            }
            $value = filter_var($r->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }
        if ($normalized) {
            $r->merge($normalized);
        }
        $d = $r->validate(['page' => 'sometimes|integer|min:1', 'status' => 'nullable|in:pending,error,created,updated,skipped', 'undecided' => 'nullable|boolean', 'attention' => 'nullable|boolean']);
        $b = CustomerImport::findOrFail($id);
        $page = $b->rows()
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($d['undecided'] ?? false, fn ($q) => $q->where('decision', 'pending'))
            ->when($d['attention'] ?? false, fn ($q) => $q->where(fn ($rows) => $rows->where('decision', 'pending')->orWhere('status', 'error')))
            ->orderBy('row_number')->paginate(20);
        $page->getCollection()->each(function ($row) {
            if ($row->payload && ! count($row->errors ?? []) && ! in_array($row->status, ['created', 'updated', 'skipped'])) {
                $row->matches = app(CustomerRegistry::class)->duplicates($row->payload)->map->only(['id', 'first_name', 'last_name', 'email', 'phone', 'status', 'version'])->all();
            }
        });

        return $page;
    }

    public function store(Request $r, CustomerImports $service)
    {
        $this->permit('write');
        $d = $r->validate([
            'file_id' => 'required|integer|min:1',
            'automatic' => 'sometimes|boolean',
            'sheet_name' => 'nullable|string|max:255',
            'delimiter' => ['nullable', 'string', Rule::in([',', ';', '|', 'tab'])],
            'shop_id' => 'prohibited',
        ]);
        $d['automatic'] = (bool) ($d['automatic'] ?? false);
        abort_if(! $d['automatic'] && empty($d['delimiter']), 422, 'Choose a CSV separator for the manual import workflow.');
        if (($d['delimiter'] ?? null) === 'tab') {
            $d['delimiter'] = "\t";
        }
        $b = $service->start($d, $r->user()->id);

        return $b->status === 'parsing' ? $this->queued($b, $r->user()->id) : response()->json(['data' => $b]);
    }

    public function configure(Request $r, CustomerImports $service, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['version' => 'required|integer|min:1', 'sheet_name' => 'nullable|string|max:255', 'delimiter' => ['sometimes', 'string', Rule::in([',', ';', '|', 'tab'])]]);
        if (($d['delimiter'] ?? null) === 'tab') {
            $d['delimiter'] = "\t";
        }

        return $this->queued($service->configure($id, $d, 'parse'), $r->user()->id);
    }

    public function mapping(Request $r, CustomerImports $service, int $id)
    {
        $this->permit('write');
        $rules = [
            'version' => 'required|integer|min:1',
            'mapping' => 'required|array:'.implode(',', Customer::FIELDS),
            'mapping.first_name' => 'required|integer|min:0',
            'mapping.last_name' => 'required|integer|min:0',
            'mapping.*' => 'integer|min:0|max:31',
            'additional_mapping' => 'sometimes|array|max:32',
            'additional_mapping.*.action' => 'required_with:additional_mapping|in:custom,ignore',
            'additional_mapping.*.key' => 'nullable|string|max:100',
            'date_format' => 'required|in:Y-m-d,d/m/Y,m/d/Y,excel',
        ];

        return $this->queued($service->configure($id, $r->validate($rules), 'preview'), $r->user()->id);
    }

    public function decisions(Request $r, CustomerImports $service, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['version' => 'required|integer|min:1', 'rows' => 'required|array|min:1|max:100', 'rows.*.id' => 'required|integer|min:1|distinct', 'rows.*.decision' => 'required|in:create,create_distinct,update,skip', 'rows.*.target_customer_id' => 'nullable|integer|min:1', 'rows.*.target_version' => 'nullable|integer|min:1', 'rows.*.overwrite_blanks' => 'sometimes|boolean', 'rows.*.reason' => 'nullable|string|max:2000']);

        return ['data' => $service->decide($id, $d)];
    }

    public function correct(Request $r, CustomerImports $service, int $id, int $row)
    {
        $this->permit('write');
        $d = $r->validate([
            'version' => 'required|integer|min:1',
            'payload' => 'required|array',
            'custom_fields' => 'sometimes|array|max:20',
            'custom_fields.*' => 'nullable|string|max:4096',
        ]);

        return ['data' => $service->correct($id, $row, $d)];
    }

    public function commit(Request $r, CustomerImports $service, int $id)
    {
        $this->permit('write');

        return $this->queued($service->commit($id, $r->validate(['version' => 'required|integer|min:1'])), $r->user()->id);
    }

    public function retry(Request $r, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $b = DB::transaction(function () use ($id, $d) {
            $b = CustomerImport::lockForUpdate()->findOrFail($id);
            abort_unless($b->version === $d['version'], 409, 'Import changed. Refresh first.');
            abort_unless(in_array($b->status, ['failed', 'parsing', 'previewing', 'committing']), 409, 'This import does not need a retry.');
            $b->status = match ($b->stage) {
                'parse' => 'parsing','preview' => 'previewing','commit' => 'committing'
            };
            $b->last_error = null;
            $b->save();

            return $b;
        });

        return $this->queued($b, $r->user()->id);
    }
}
