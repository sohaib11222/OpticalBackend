<?php

namespace App\Tenancy;

use App\Models\ShopMembership;
use LogicException;

class TenantContext
{
    private ?ShopMembership $membership = null;

    public function set(ShopMembership $membership): void
    {
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->membership = null;
    }

    public function id(): ?int
    {
        return $this->membership?->shop_id;
    }

    public function membership(): ShopMembership
    {
        return $this->membership ?? throw new LogicException('A validated tenant context is required.');
    }

    public function cacheKey(string $key): string
    {
        return 'shop:'.$this->membership()->shop_id.':'.$key;
    }
}
