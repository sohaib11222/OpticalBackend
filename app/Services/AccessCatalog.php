<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;

/** Stable permission vocabulary shared by migrations and the employee editor. */
class AccessCatalog
{
    public const MODULES = ['customers', 'optical', 'agenda', 'products', 'stock', 'orders', 'files'];

    public const ACTIONS = ['create', 'edit', 'archive', 'print', 'export'];

    public const NEW_PERMISSIONS = ['employees.read', 'employees.manage', 'audit.read', 'audit.export', 'audit.print', 'operations.read', 'reminders.read', 'reminders.write', 'followups.read', 'followups.write', 'communications.read', 'communications.send', 'communications.manage', 'payments.read', 'payments.write', 'payments.reverse', 'payments.print', 'payments.export'];

    public static function additions(): array
    {
        $p = self::NEW_PERMISSIONS;
        foreach (self::MODULES as $module) {
            foreach (self::ACTIONS as $action) {
                $p[] = "$module.$action";
            }
        }

        return $p;
    }

    public static function presets(): array
    {
        $common = ['shop.view', 'operations.read'];

        return [
            'optometrist' => [...$common, 'customers.read', 'optical.read', 'optical.write', 'optical.create', 'optical.edit', 'optical.finalize', 'optical.print', 'agenda.read', 'agenda.write', 'agenda.create', 'agenda.edit', 'agenda.archive', 'reminders.read', 'reminders.write', 'followups.read', 'communications.read', 'communications.send'],
            'salesperson' => [...$common, 'customers.read', 'products.read', 'stock.read', 'orders.read', 'reminders.read'],
            'cashier' => [...$common, 'customers.read', 'products.read', 'orders.read', 'orders.write', 'orders.create', 'orders.edit', 'orders.fulfil', 'orders.print', 'reports.read', 'payments.read', 'payments.write', 'payments.print', 'reminders.read'],
            'warehouse' => [...$common, 'products.read', 'stock.read', 'stock.write', 'stock.create', 'stock.edit', 'stock.print', 'orders.read', 'reminders.read'],
            'store_administrator' => [...$common, 'customers.read', 'products.read', 'orders.read', 'agenda.read', 'files.read', 'reminders.read', 'followups.read', 'communications.read'],
        ];
    }

    public static function install(): void
    {
        foreach (self::additions() as $name) {
            $p = Permission::firstOrCreate(['name' => $name]);
            Role::where('name', 'owner')->firstOrFail()->permissions()->syncWithoutDetaching([$p->id]);
            $admin = Permission::firstOrCreate(['name' => 'shops.'.$name]);
            Role::where('name', 'system_admin')->firstOrFail()->permissions()->syncWithoutDetaching([$admin->id]);
        }
        // Preserve previously configured custom roles when adding finer operation checks.
        foreach (Role::whereNotIn('name', ['owner', 'system_admin'])->get() as $role) {
            $existing = $role->permissions()->pluck('name')->all();
            foreach (self::MODULES as $module) {
                $suffixes = in_array($module.'.write', $existing) ? ['create', 'edit', 'archive'] : [];
                foreach ($suffixes as $suffix) {
                    $role->permissions()->syncWithoutDetaching([Permission::where('name', "$module.$suffix")->value('id')]);
                }
            }
        }
        foreach (self::presets() as $name => $permissions) {
            $role = Role::firstOrCreate(['name' => $name]);
            $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
        }
    }
}
