<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrivateFile;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrivateFileController extends Controller
{
    public function show(Request $r, TenantContext $c, string $id)
    {
        abort_unless($c->membership()->can('files.read'), 403);
        $f = PrivateFile::findOrFail($id);
        abort_unless($f->scan_status === 'clean' && ! $f->archived_at, 409, 'This file is not available.');
        abort_unless(str_starts_with($f->storage_key, 'shops/'.$c->id().'/') && ! str_contains($f->storage_key, '..'), 404);
        abort_unless(Storage::disk('private')->exists($f->storage_key), 404);
        Audit::record('file.download', $c->id(), $r->user()->id, 'private_file', $f->id);

        return Storage::disk('private')->download($f->storage_key, basename($f->filename), ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
