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

    /**
     * Returns the current context without turning an absent context into an
     * exception. Queue middleware uses this to safely nest a synchronous job
     * inside an authenticated HTTP request.
     */
    public function current(): ?ShopMembership
    {
        return $this->membership;
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
