<?php

namespace App\Services\Commerce;

use Illuminate\Database\Eloquent\Builder;

class SalesLedger
{
    public static function summary(Builder $q)
    {
        return (clone $q)->select('currency', 'price_decimals')->selectRaw("SUM(CASE WHEN kind = 'sale' THEN amount_minor ELSE 0 END) AS sales_minor, SUM(CASE WHEN kind = 'reversal' THEN amount_minor ELSE 0 END) AS reversals_minor")->groupBy('currency', 'price_decimals')->orderBy('currency')->get()->map(fn ($s) => ['currency' => $s->currency, 'price_decimals' => (int) $s->price_decimals, 'sales_minor' => (int) $s->sales_minor, 'reversals_minor' => (int) $s->reversals_minor, 'net_minor' => (int) $s->sales_minor - (int) $s->reversals_minor]);
    }
}
