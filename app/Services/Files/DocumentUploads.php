<?php

namespace App\Services\Files;

use App\Jobs\ScanPrivateFile;
use App\Models\Customer;
use App\Models\OpticalRecord;
use App\Models\PrivateFile;
use App\Models\Shop;
use App\Services\Audit;
use App\Services\CustomerRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentUploads
{
    public function upload(UploadedFile $upload, array $d, int $actor): PrivateFile
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        $mime = $upload->getMimeType();
        if ($d['category'] !== 'document' && ! in_array($mime, ['image/png', 'image/jpeg'])) {
            throw ValidationException::withMessages(['file' => 'Customer and glasses images must be PNG or JPEG files.']);
        }
        $allowed = ['pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'], 'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
        if (! isset($allowed[$extension]) || ! in_array($mime, $allowed[$extension], true)) {
            throw ValidationException::withMessages(['file' => 'File contents do not match an allowed document format.']);
        }
        if ($d['category'] !== 'document' && ! in_array($extension, ['png', 'jpg', 'jpeg'])) {
            throw ValidationException::withMessages(['file' => 'Customer and glasses images must be PNG or JPEG.']);
        }
        if (in_array($extension, ['png', 'jpg', 'jpeg'])) {
            $size = @getimagesize($upload->getRealPath());
            if (! $size || $size[0] * $size[1] > 25000000) {
                throw ValidationException::withMessages(['file' => 'Invalid image or image dimensions exceed 25 megapixels.']);
            }
        }
        if (in_array($extension, ['docx', 'xlsx'])) {
            $this->validateOffice($upload->getRealPath(), $extension);
        }
        $hash = hash_file('sha256', $upload->getRealPath());
        $key = 'shops/'.app(TenantContext::class)->id().'/documents/'.Str::uuid().'.'.$extension;
        $stored = false;
        try {
            return DB::transaction(function () use ($upload, $d, $actor, $hash, $key, $mime, &$stored) {
                Shop::lockForUpdate()->findOrFail(app(TenantContext::class)->id());
                if ($existing = PrivateFile::where('upload_key', $d['upload_key'])->first()) {
                    abort_unless(hash_equals($existing->sha256, $hash) && $existing->customer_id == ($d['customer_id'] ?? null) && $existing->optical_record_id == ($d['optical_record_id'] ?? null) && $existing->category === $d['category'] && $existing->title === $d['title'] && $existing->description === ($d['description'] ?? null), 409, 'This upload key was already used for different content or links.');

                    return $existing;
                }
                $customer = isset($d['customer_id']) ? Customer::findOrFail($d['customer_id']) : null;
                if ($customer) {
                    abort_unless($customer->status === 'active', 409, 'Restore this customer before adding documents.');
                }
                if (! empty($d['optical_record_id'])) {
                    $record = OpticalRecord::where('customer_id', $customer?->id)->findOrFail($d['optical_record_id']);
                    if ($d['category'] === 'glasses_image') {
                        abort_unless($record->kind === 'glasses', 422, 'Select a glasses record for a glasses image.');
                    }
                }
                if ($d['category'] !== 'document' && ! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'A customer is required for private customer images.']);
                }
                if ($d['category'] === 'glasses_image' && empty($d['optical_record_id'])) {
                    throw ValidationException::withMessages(['optical_record_id' => 'Select the linked glasses record.']);
                }
                $stream = fopen($upload->getRealPath(), 'rb');
                try {
                    Storage::disk('private')->put($key, $stream);
                } finally {
                    fclose($stream);
                }$stored = true;
                $filename = preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '_', basename($upload->getClientOriginalName()));
                $f = PrivateFile::create(['storage_key' => $key, 'filename' => mb_substr($filename, 0, 240), 'mime' => $mime, 'size' => $upload->getSize(), 'sha256' => $hash, 'upload_key' => $d['upload_key'], 'title' => $d['title'], 'description' => $d['description'] ?? null, 'category' => $d['category'], 'customer_id' => $customer?->id, 'optical_record_id' => $d['optical_record_id'] ?? null, 'uploaded_by' => $actor]);
                if ($customer) {
                    app(CustomerRegistry::class)->event($customer, 'document.uploaded', $actor, [], null, 'private_file', $f->id);
                } else {
                    Audit::record('document.uploaded', $f->shop_id, $actor, 'private_file', $f->id);
                }

                return $f->refresh();
            }, 3);
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('private')->delete($key);
            }throw $e;
        }
    }

    private function validateOffice(string $path, string $extension): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Invalid Office document.']);
        }
        try {
            $total = 0;
            if ($zip->numFiles > 2000) {
                throw new \RuntimeException;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $total += $entry['size'];
                if ($total > 50 * 1024 * 1024 || str_contains($entry['name'], '..') || preg_match('/(?:vbaProject|activeX|embeddings)\b/i', $entry['name'])) {
                    throw new \RuntimeException;
                }
            }
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($extension === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml') === false) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Unsupported or oversized Office container. Macros and embedded objects are not accepted.']);
        } finally {
            $zip->close();
        }
    }

    public function queue(PrivateFile $f): void
    {
        if (in_array($f->scan_status, ['pending', 'failed']) && ! $f->archived_at) {
            try {
                ScanPrivateFile::dispatch($f->shop_id, $f->uploaded_by, $f->id)->afterCommit();
            } catch (\Throwable $e) {
                $f->forceFill(['scan_status' => 'failed', 'scan_error' => 'queue_unavailable'])->save();
                report($e);
            }
        }
    }
}
