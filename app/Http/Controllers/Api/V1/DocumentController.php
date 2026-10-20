<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\OpticalRecord;
use App\Models\PrivateFile;
use App\Services\Audit;
use App\Services\CustomerRegistry;
use App\Services\Files\DocumentUploads;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    private function permit(string $action): void
    {
        abort_unless(app(TenantContext::class)->membership()->can('files.'.$action), 403);
    }

    public function index(Request $r)
    {
        $this->permit('read');
        $d = $r->validate(['customer_id' => 'nullable|integer|min:1', 'optical_record_id' => 'nullable|integer|min:1', 'category' => 'nullable|in:document,customer_image,glasses_image', 'search' => 'nullable|string|max:150', 'scan_status' => 'nullable|in:pending,clean,rejected,failed', 'archived' => 'nullable|boolean', 'page' => 'nullable|integer|min:1']);
        if (! empty($d['customer_id'])) {
            Customer::findOrFail($d['customer_id']);
        }if (! empty($d['optical_record_id'])) {
            OpticalRecord::findOrFail($d['optical_record_id']);
        }$q = PrivateFile::query();
        foreach (['customer_id', 'optical_record_id', 'category', 'scan_status'] as $field) {
            if (! empty($d[$field])) {
                $q->where($field, $d[$field]);
            }
        }($d['archived'] ?? false) ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        if (! empty($d['search'])) {
            $q->whereRaw("title LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $d['search']).'%']);
        }

        return $q->orderByDesc('id')->paginate(20);
    }

    public function show(int $id)
    {
        $this->permit('read');

        return ['data' => PrivateFile::findOrFail($id)];
    }

    public function store(Request $r, DocumentUploads $uploads)
    {
        $this->permit('write');
        $d = $r->validate(['file' => 'required|file|max:10240', 'title' => 'required|string|max:255', 'description' => 'nullable|string|max:2000', 'category' => 'required|in:document,customer_image,glasses_image', 'customer_id' => 'nullable|integer|min:1', 'optical_record_id' => 'nullable|integer|min:1|required_if:category,glasses_image', 'upload_key' => 'required|uuid', 'shop_id' => 'prohibited', 'scan_status' => 'prohibited']);
        $f = $uploads->upload($r->file('file'), $d, $r->user()->id);
        $result = $f->toArray();
        $uploads->queue($f);

        return response()->json(['data' => $result], 202);
    }

    public function retry(DocumentUploads $uploads, int $id)
    {
        $this->permit('write');
        $f = PrivateFile::findOrFail($id);
        abort_unless(in_array($f->scan_status, ['pending', 'failed']) && ! $f->archived_at, 409, 'This file cannot be rescanned.');
        $result = $f->toArray();
        $uploads->queue($f);

        return response()->json(['data' => $result], 202);
    }

    public function archive(Request $r, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['version' => 'required|integer|min:1', 'archived' => 'required|boolean', 'reason' => 'required|string|min:3|max:2000']);

        return DB::transaction(function () use ($r, $id, $d) {
            $f = PrivateFile::lockForUpdate()->findOrFail($id);
            abort_unless($f->version === $d['version'], 409, 'Document changed. Refresh before updating.');
            $f->archived_at = $d['archived'] ? now() : null;
            $f->archive_reason = $d['reason'];
            $f->version++;
            $f->save();
            if ($f->customer_id) {
                app(CustomerRegistry::class)->event(Customer::findOrFail($f->customer_id), $d['archived'] ? 'document.archived' : 'document.restored', $r->user()->id, [], $d['reason'], 'private_file', $f->id);
            } else {
                Audit::record('document.status_changed', $f->shop_id, $r->user()->id, 'private_file', $f->id);
            }

            return ['data' => $f];
        });
    }

    public function update(Request $r, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['version' => 'required|integer|min:1', 'title' => 'required|string|max:255', 'description' => 'nullable|string|max:2000']);

        return DB::transaction(function () use ($r, $id, $d) {
            $f = PrivateFile::lockForUpdate()->findOrFail($id);
            abort_unless($f->version === $d['version'], 409, 'Document changed. Refresh before editing.');
            $f->title = $d['title'];
            $f->description = $d['description'] ?? null;
            $f->version++;
            $f->save();
            Audit::record('document.metadata_updated', $f->shop_id, $r->user()->id, 'private_file', $f->id);

            return ['data' => $f];
        });
    }

    public function preview(Request $r, int $id)
    {
        $this->permit('read');
        $f = PrivateFile::findOrFail($id);
        abort_unless($f->scan_status === 'clean' && ! $f->archived_at, 409, 'This file is not available.');
        abort_unless(in_array($f->mime, ['image/png', 'image/jpeg', 'application/pdf']), 422, 'Download this document to view it.');
        abort_unless(str_starts_with($f->storage_key, 'shops/'.app(TenantContext::class)->id().'/') && ! str_contains($f->storage_key, '..'), 404);
        Audit::record('document.preview', $f->shop_id, $r->user()->id, 'private_file', $f->id);

        return Storage::disk('private')->response($f->storage_key, $f->filename, ['Content-Type' => $f->mime, 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }
}
