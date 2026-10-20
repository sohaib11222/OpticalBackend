<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, ...$this->resource->only(Customer::FIELDS), 'date_of_birth' => $this->date_of_birth?->format('Y-m-d'), 'status' => $this->status, 'version' => $this->version, 'source' => $this->source, 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
