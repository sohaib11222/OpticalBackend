<?php
namespace App\Services\Communications;

use App\Models\CommunicationBatch;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Services\Audit;
use App\Services\Commerce\ShopLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkMessages
{
    public function preview(array $d): array
    {
        $customers = Customer::whereIn('id', $d['customer_ids'])->get()->keyBy('id');
        // Never reveal whether a foreign tenant's customer exists.
        abort_unless($customers->count() === count($d['customer_ids']), 404, 'One or more selected customers are unavailable.');
        $seen = [];
        $rows = [];
        $messages = app(Messages::class);
        foreach ($d['customer_ids'] as $id) {
            $c = $customers[$id];
            $reason = $c->status !== 'active' ? 'customer_archived' : null;
            $recipient = null;
            try { $recipient = Messages::recipient($c, $d['channel']); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $reason ??= 'invalid_recipient'; }
            if ($recipient && ! $messages->recipientAllowed($c, $d['channel'], $d['purpose'], $recipient)) $reason ??= 'recipient_opted_out';
            if ($recipient && isset($seen[$recipient])) $reason ??= 'duplicate_recipient';
            if ($recipient) $seen[$recipient] = true;
            $rows[] = ['customer_id' => $id, 'name' => $c->first_name.' '.$c->last_name, 'recipient' => $recipient, 'eligible' => $reason === null, 'reason' => $reason];
        }
        return ['rows' => $rows, 'eligible' => count(array_filter($rows, fn ($r) => $r['eligible'])), 'total' => count($rows)];
    }

    public function send(array $d, int $actor): CommunicationBatch
    {
        $d['customer_ids'] = array_map('intval', $d['customer_ids']);
        sort($d['customer_ids'], SORT_NUMERIC);
        $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($d, $actor, $hash) {
            $shop = ShopLock::acquire();
            if ($old = CommunicationBatch::where('creation_key', $d['creation_key'])->first()) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'This batch key was already used for different details.');
                return $old;
            }
            $preview = $this->preview($d);
            if ($preview['eligible'] !== $preview['total']) {
                $errors = [];
                foreach ($preview['rows'] as $row) if (! $row['eligible']) $errors['customer_ids.'.$row['customer_id']] = [$row['reason']];
                throw ValidationException::withMessages($errors);
            }
            $ids = [];
            foreach ($d['customer_ids'] as $id) {
                $payload = $d;
                unset($payload['customer_ids']);
                $payload['customer_id'] = $id;
                $ids[] = app(Messages::class)->queue($payload, $actor, null, 'bulk:'.$d['creation_key'].':'.$id)->id;
            }
            $batch = CommunicationBatch::create(['creation_key' => $d['creation_key'], 'request_hash' => $hash, 'channel' => $d['channel'], 'message_ids' => $ids, 'created_by' => $actor]);
            Audit::record('message_batch.scheduled', $shop->id, $actor, 'communication_batch', $batch->id);
            return $batch;
        }, 3);
    }
}
