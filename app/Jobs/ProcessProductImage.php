<?php

namespace App\Jobs;

use App\Jobs\Middleware\WithTenant;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Audit;
use App\Services\Commerce\ShopLock;
use App\Services\Files\FileScanner;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessProductImage implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $shopId, public int $actorId, public int $imageId)
    {
        $this->onQueue('uploads');
    }

    public function middleware(): array
    {
        return [new WithTenant($this->shopId, $this->actorId), (new WithoutOverlapping('product-image:'.$this->shopId.':'.$this->imageId))->releaseAfter(30)->expireAfter(120)];
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(FileScanner $scanner): void
    {
        abort_unless(app(TenantContext::class)->membership()->can('products.write'), 403);
        $image = ProductImage::findOrFail($this->imageId);
        if (in_array($image->scan_status, ['clean', 'rejected']) || $image->archived_at) {
            return;
        }
        try {
            abort_unless(str_starts_with($image->storage_key, 'shops/'.$this->shopId.'/catalog/') && ! str_contains($image->storage_key, '..'), 404);
            $disk = Storage::disk('private');
            $path = $disk->path($image->storage_key);
            abort_unless(hash_equals($image->sha256, hash_file('sha256', $path)), 409, 'Image integrity check failed.');
            $stream = $disk->readStream($image->storage_key);
            try {
                $clean = $scanner->scan($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $clean) {
                $image->scan_status = 'rejected';
                $image->scan_error = 'unsafe_content';
                $image->scanned_at = now();
                $image->save();

                return;
            }
            $decoded = @imagecreatefromstring($disk->get($image->storage_key));
            if (! $decoded) {
                throw new \RuntimeException('Image cannot be decoded.');
            }
            $display = 'shops/'.$this->shopId.'/catalog/display/'.$image->id.($image->mime === 'image/png' ? '.png' : '.jpg');
            ob_start();
            try {
                if ($image->mime === 'image/png') {
                    imagealphablending($decoded, false);
                    imagesavealpha($decoded, true);
                    imagepng($decoded, null, 6);
                } else {
                    imagejpeg($decoded, null, 90);
                }$bytes = ob_get_contents();
            } finally {
                ob_end_clean();
                imagedestroy($decoded);
            }
            if (! $bytes || strlen($bytes) > 20 * 1024 * 1024) {
                throw new \RuntimeException('Processed image exceeds safe limits.');
            }
            $disk->put($display, $bytes);
            DB::transaction(function () use ($image, $display) {
                ShopLock::acquire();
                $fresh = ProductImage::lockForUpdate()->findOrFail($image->id);
                if ($fresh->archived_at) {
                    return;
                }
                $fresh->display_key = $display;
                $fresh->scan_status = 'clean';
                $fresh->scan_error = null;
                $fresh->scanned_at = now();
                $fresh->save();
                $p = Product::lockForUpdate()->findOrFail($image->product_id);
                if (! $p->primary_image_id) {
                    $p->primary_image_id = $image->id;
                    $p->version++;
                    $p->save();
                }
                Audit::record('product_image.clean', $this->shopId, $this->actorId, 'product_image', $image->id);
            });
        } catch (\Throwable $e) {
            $image->scan_status = 'failed';
            $image->scan_error = 'processing_unavailable';
            $image->save();
            throw $e;
        }
    }
}
