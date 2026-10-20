<?php

namespace App\Models;

class OptometryRecord extends OpticalRecord
{
    protected $table = 'optical_records';

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('kind', fn ($q) => $q->where('kind', 'optometry'));
    }
}
