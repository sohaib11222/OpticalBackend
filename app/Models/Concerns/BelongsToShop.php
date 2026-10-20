<?php

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait BelongsToShop
{
    protected static function bootBelongsToShop(): void
    {
        static::addGlobalScope('shop', function (Builder $query) {
            $id = app(TenantContext::class)->id();
            $id ? $query->where($query->getModel()->qualifyColumn('shop_id'), $id) : $query->whereRaw('1 = 0');
        });
        static::deleting(function ($model) {
            $id = app(TenantContext::class)->membership()->shop_id;
            if ((int) $model->getOriginal('shop_id') !== $id) {
                throw new LogicException('Cannot delete data outside the current shop.');
            }
        });
        static::saving(function ($model) {
            $id = app(TenantContext::class)->membership()->shop_id;
            if (($model->shop_id && (int) $model->shop_id !== $id) || ($model->exists && (int) $model->getOriginal('shop_id') !== $id)) {
                throw new LogicException('Tenant ownership cannot be changed.');
            } $model->shop_id = $id;
        });
    }
}
