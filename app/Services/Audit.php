<?php

namespace App\Services;

use App\Models\AuditEvent;

class Audit
{
    public static function record(string $action, ?int $shopId = null, ?int $actorId = null, ?string $subjectType = null, ?int $subjectId = null): void
    {
        AuditEvent::create(['action' => $action, 'shop_id' => $shopId, 'actor_id' => $actorId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'request_id' => app()->runningInConsole() ? null : request()->attributes->get('request_id')]);
    }
}
