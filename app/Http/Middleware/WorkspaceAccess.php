<?php

namespace App\Http\Middleware;

use App\Services\Audit;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/** Additional operation checks; existing controller policies remain authoritative. */
class WorkspaceAccess
{
    public function handle(Request $r, Closure $next)
    {
        $controller = class_basename(explode('@', $r->route()->getActionName())[0]);
        $method = $r->route()->getActionMethod();
        $module = match ($controller) {
            'CustomerController' => 'customers', 'OpticalController' => 'optical', 'AgendaController' => 'agenda',
            'CatalogController' => in_array($method, ['inventory', 'movements', 'move', 'reverse']) ? 'stock' : 'products',
            'ProductImageController' => 'products', 'OrderController' => 'orders', 'DocumentController', 'PrivateFileController' => 'files', default => null,
        };
        $action = null;
        if ($module) {
            $action = match ($method) {
                'store', 'amend', 'move' => 'create', 'update', 'retry', 'reverse' => 'edit',
                'transition' => match ($r->route('action')) {
                    'cancel' => 'archive', 'confirm' => 'edit', default => null
                },
                'archive' => 'archive', 'status' => ($module === 'agenda' && $r->input('status') !== 'cancelled') ? 'edit' : 'archive',
                'categorySave' => $r->route('id') ? 'edit' : 'create', default => null,
            };
            if ($controller === 'ProductImageController' && $method === 'store') {
                $action = 'edit';
            }
            if ($controller === 'ProductImageController' && $method === 'update' && $r->input('action') === 'archive') {
                $action = 'archive';
            }
            if ($method === 'categorySave' && $r->input('status') === 'archived') {
                abort_unless(app(TenantContext::class)->membership()->can('products.archive'), 403);
            }
            if ($r->isMethodSafe() && $r->query('purpose') === 'print') {
                $action = 'print';
            }
            if ($action) {
                abort_unless(app(TenantContext::class)->membership()->can($module.'.'.$action), 403, 'This operation is not included in your permissions.');
            }
        }
        $response = $next($r);
        if ($module && $response->getStatusCode() < 400 && ($action || $method === 'show')) {
            Audit::record($module.'.'.($action ?? 'view'), app(TenantContext::class)->id(), $r->user()->id, $module, is_numeric($r->route('id')) ? (int) $r->route('id') : null);
        }

        return $response;
    }
}
