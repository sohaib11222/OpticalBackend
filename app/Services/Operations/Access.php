<?php

namespace App\Services\Operations;

use App\Models\Reminder;
use App\Tenancy\TenantContext;

class Access
{
    public static function can(string $permission): bool
    {
        return app(TenantContext::class)->membership()->can($permission);
    }

    public static function require(string $permission): void
    {
        abort_unless(self::can($permission), 403, 'This operation is not included in your permissions.');
    }

    public static function scopeReminders($query): void
    {
        foreach (['customer_id' => 'customers.read', 'optical_record_id' => 'optical.read', 'order_id' => 'orders.read', 'appointment_id' => 'agenda.read'] as $field => $permission) {
            if (! self::can($permission)) {
                $query->whereNull($field);
            }
        }
    }

    public static function canReadReminder(Reminder $reminder): bool
    {
        if (! self::can(self::reminderPermission($reminder->kind))) {
            return false;
        }
        foreach (['customer_id' => 'customers.read', 'optical_record_id' => 'optical.read', 'order_id' => 'orders.read', 'appointment_id' => 'agenda.read'] as $field => $permission) {
            if ($reminder->$field && ! self::can($permission)) {
                return false;
            }
        }

        return ! $reminder->customer_id || self::can('customers.read');
    }

    public static function reminderPermission(string $kind): string
    {
        return match ($kind) {
            'checkup', 'contact_lens' => 'optical.read', 'order' => 'orders.read', 'followup' => 'followups.read', default => 'customers.read',
        };
    }
}
