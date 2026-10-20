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
            ProcessCustomerImport::dispatch($b->shop_id, $actor, $b->id, $b->version)->afterCommit();
        } catch (\Throwable $e) {
            $b->forceFill(['status' => 'failed', 'last_error' => 'Processing could not be started. Check the upload worker and retry this import.'])->save();
            $data = $b->toArray();
        }

        return response()->json(['data' => $data], 202);
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

        return ['data' => $b, 'counts' => $b->rows()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'), 'undecided' => $b->rows()->where('decision', 'pending')->count()];
    }

    public function rows(Request $r, int $id)
    {
        $this->permit('read');
        $d = $r->validate(['page' => 'sometimes|integer|min:1', 'status' => 'nullable|in:pending,error,created,updated,skipped', 'undecided' => 'nullable|boolean']);
        $b = CustomerImport::findOrFail($id);
        $page = $b->rows()->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($d['undecided'] ?? false, fn ($q) => $q->where('decision', 'pending'))->orderBy('row_number')->paginate(20);
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
        $d = $r->validate(['file_id' => 'required|integer|min:1', 'sheet_name' => 'nullable|string|max:255', 'delimiter' => ['required', 'string', Rule::in([',', ';', '|', 'tab'])], 'shop_id' => 'prohibited']);
        if ($d['delimiter'] === 'tab') {
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
        $rules = ['version' => 'required|integer|min:1', 'mapping' => 'required|array:'.implode(',', Customer::FIELDS), 'mapping.first_name' => 'required|integer|min:0', 'mapping.last_name' => 'required|integer|min:0', 'mapping.*' => 'integer|min:0|max:31', 'date_format' => 'required|in:Y-m-d,d/m/Y,m/d/Y,excel'];

        return $this->queued($service->configure($id, $r->validate($rules), 'preview'), $r->user()->id);
    }

    public function decisions(Request $r, CustomerImports $service, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['version' => 'required|integer|min:1', 'rows' => 'required|array|min:1|max:100', 'rows.*.id' => 'required|integer|min:1|distinct', 'rows.*.decision' => 'required|in:create,create_distinct,update,skip', 'rows.*.target_customer_id' => 'nullable|integer|min:1', 'rows.*.target_version' => 'nullable|integer|min:1', 'rows.*.overwrite_blanks' => 'sometimes|boolean', 'rows.*.reason' => 'nullable|string|max:2000']);

        return ['data' => $service->decide($id, $d)];
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
