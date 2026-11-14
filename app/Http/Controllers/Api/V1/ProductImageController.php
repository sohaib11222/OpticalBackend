<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessProductImage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Audit;
use App\Services\Commerce\ShopLock;
use App\Services\Files\FileScanner;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductImageController extends Controller
{
    public function fromUrl(Request $r, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['url' => 'required|url:https|max:2048', 'upload_key' => 'required|uuid', 'shop_id' => 'prohibited']);
        $product = Product::findOrFail($id);
        abort_if($product->status === 'archived', 409, 'Restore the product before adding photographs.');
        $file = app(\App\Services\Files\RemoteProductPhoto::class)->download($d['url']);
        try {
            // The URL validation above already cached the original empty file bag.
            $upload = Request::createFrom($r);
            $upload->files->set('file', $file);
            return $this->store($upload, $id);
        } finally {
            @unlink($file->getPathname());
        }
    }

    private function permit(string $action): void
    {
        abort_unless(app(TenantContext::class)->membership()->can('products.'.$action), 403);
    }

    private function queue(ProductImage $i): void
    {
        try {
            ProcessProductImage::dispatch($i->shop_id, $i->uploaded_by, $i->id)->afterCommit();
        } catch (\Throwable $e) {
            $i->forceFill(['scan_status' => 'failed', 'scan_error' => 'queue_unavailable'])->save();
            report($e);
        }
    }

    /**
     * Scan and normalize ordinary catalogue photographs before acknowledging
     * the upload. This avoids a misleading permanent "pending" state when a
     * queue worker is unavailable, while retaining the same MIME, size,
     * integrity and antivirus checks used by the queued job.
     */
    private function process(ProductImage $image): ProductImage
    {
        if (! config('documents.product_images_sync', true)) {
            $this->queue($image);

            return $image;
        }

        try {
            (new ProcessProductImage($image->shop_id, $image->uploaded_by, $image->id))
                ->handle(app(FileScanner::class));
        } catch (\Throwable $e) {
            report($e);
            $fresh = ProductImage::find($image->id);

            // ProcessProductImage records a failed state itself. Returning a
            // clear service error lets the UI explain what happened rather
            // than claiming an upload is still pending indefinitely.
            abort(503, $fresh?->scan_status === 'failed'
                ? 'The image was saved but could not be safety-processed. Please retry the photograph.'
                : 'Image safety processing is temporarily unavailable. Please retry the photograph.');
        }

        $fresh = ProductImage::findOrFail($image->id);
        abort_if($fresh->scan_status === 'rejected', 422, 'This image was rejected by the security scan. Choose a different photograph.');
        abort_unless($fresh->scan_status === 'clean' && $fresh->display_key, 503, 'Image processing did not complete. Please retry the photograph.');

        return $fresh;
    }

    public function store(Request $r, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['file' => 'required|file|max:10240', 'upload_key' => 'required|uuid', 'shop_id' => 'prohibited']);
        $file = $r->file('file');
        $mime = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp']) || ! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
            throw ValidationException::withMessages(['file' => 'Choose a PNG, JPEG or WebP photograph.']);
        }
        $size = @getimagesize($file->getRealPath());
        if (! $size || $size[0] * $size[1] > 25000000) {
            throw ValidationException::withMessages(['file' => 'Image dimensions must not exceed 25 megapixels.']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $key = 'shops/'.app(TenantContext::class)->id().'/catalog/source/'.Str::uuid().match ($mime) { 'image/png' => '.png', 'image/webp' => '.webp', default => '.jpg' };
        $stored = false;
        try {
            $image = DB::transaction(function () use ($id, $d, $file, $mime, $hash, $key, &$stored, $r) {
                ShopLock::acquire();
                $p = Product::findOrFail($id);
                abort_unless($p->status !== 'archived', 409, 'Restore the product before adding photographs.');
                if ($old = ProductImage::where('upload_key', $d['upload_key'])->first()) {
                    abort_unless($old->product_id === $id && hash_equals($old->sha256, $hash), 409, 'Upload key already belongs to different content.');

                    return $old;
                }
                abort_if($p->images()->whereNull('archived_at')->count() >= 100, 422, 'Up to one hundred current photographs are allowed per product family item.');
                $stream = fopen($file->getRealPath(), 'rb');
                try {
                    Storage::disk('private')->put($key, $stream);
                } finally {
                    fclose($stream);
                }$stored = true;
                $image = ProductImage::create(['product_id' => $id, 'storage_key' => $key, 'filename' => mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '_', basename($file->getClientOriginalName())), 0, 240), 'mime' => $mime, 'size' => $file->getSize(), 'sha256' => $hash, 'upload_key' => $d['upload_key'], 'uploaded_by' => $r->user()->id]);
                Audit::record('product_image.uploaded', $p->shop_id, $r->user()->id, 'product_image', $image->id);

                return $image->refresh();
            }, 3);
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('private')->delete($key);
            }throw $e;
        }
        $image = $this->process($image);

        return response()->json(['data' => $image->toArray()], 201);
    }

    public function show(int $id)
    {
        $this->permit('read');
        $image = ProductImage::findOrFail($id);
        abort_unless($image->scan_status === 'clean' && ! $image->archived_at && $image->display_key, 409, 'This photograph is not available.');
        abort_unless(str_starts_with($image->display_key, 'shops/'.app(TenantContext::class)->id().'/catalog/display/') && ! str_contains($image->display_key, '..'), 404);

        return Storage::disk('private')->response($image->display_key, 'product-'.$image->id.($image->mime === 'image/png' ? '.png' : '.jpg'), ['Content-Type' => $image->mime, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function retry(int $id)
    {
        $this->permit('write');
        $image = ProductImage::findOrFail($id);
        abort_unless(in_array($image->scan_status, ['pending', 'failed']) && ! $image->archived_at, 409, 'This image cannot be reprocessed.');
        $image->forceFill(['scan_status' => 'pending', 'scan_error' => null])->save();
        $image = $this->process($image);

        return response()->json(['data' => $image->toArray()], 200);
    }

    public function update(Request $r, int $id)
    {
        $this->permit('write');
        $d = $r->validate(['action' => 'required|in:primary,archive', 'version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $id, $d) {
            ShopLock::acquire();
            $image = ProductImage::lockForUpdate()->findOrFail($id);
            $p = Product::lockForUpdate()->findOrFail($image->product_id);
            abort_unless($p->version === (int) $d['version'], 409, 'Product changed. Refresh its photos before updating.');
            abort_unless(! $image->archived_at, 409, 'This photograph is already archived.');
            if ($d['action'] === 'primary') {
                abort_unless($image->scan_status === 'clean' && $image->display_key, 422, 'Choose a clean processed image.');
                $p->primary_image_id = $id;
            } else {
                if ($p->primary_image_id === $id) {
                    $next = $p->images()->where('id', '!=', $id)->where('scan_status', 'clean')->whereNull('archived_at')->orderBy('id')->value('id');
                    abort_if(! $next && $p->status === 'active', 409, 'Set this product to draft before removing its last clean photograph.');
                    $p->primary_image_id = $next;
                }
                $image->archived_at = now();
                $image->save();
            }
            $p->version++;
            $p->save();
            Audit::record('product_image.'.$d['action'], $p->shop_id, $r->user()->id, 'product_image', $id);

            return ['data' => $p->load(['category', 'balance', 'images', 'primaryImage'])];
        });
    }
}
