<?php

namespace App\Http\Middleware;

use App\Integrations\Vista\VistaClient;
use App\Integrations\Vista\VistaEntitlements;
use App\Integrations\Vista\VistaFailure;
use App\Models\VistaConnection;
use Closure;
use Illuminate\Http\Request;

/**
 * Enforces a paid Vista Express 360 entitlement at the CRM API boundary.
 * Standalone shops and legacy ordinary Vista integrations do not enter this
 * middleware, so their existing permissions and workflows are unchanged.
 */
class Vista360SubscriptionAccess
{
    public function __construct(
        private readonly VistaEntitlements $entitlements,
        private readonly VistaClient $client,
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        $connection = VistaConnection::where('access_mode', 'vista360')->first();
        if (! $connection) {
            return $next($request);
        }

        $snapshot = $this->entitlements->snapshot($connection);
        try {
            $entitlement = $this->entitlements->fresh($connection)
                ? $this->entitlements->payload($snapshot)
                : $this->entitlements->refresh($connection, $this->client);
        } catch (VistaFailure $failure) {
            $this->entitlements->markUnavailable($connection, $failure);
            if ($failure->reason === 'vista360_subscription_required') {
                VistaConnection::whereKey($connection->id)->update(['status' => 'subscription_required', 'last_error' => $failure->getMessage()]);
                return response()->json([
                    'message' => $failure->getMessage(),
                    'code' => 'vista360_subscription_required',
                    'data' => ['entitlement' => data_get($failure->details, 'entitlement')],
                ], 403);
            }
            if ($this->mayUseStale($snapshot)) {
                $entitlement = $this->entitlements->payload($snapshot);
            } else {
                return response()->json([
                    'message' => 'We could not verify the Vista Express 360 subscription. Please try again shortly.',
                    'code' => 'vista360_entitlement_unavailable',
                ], 503);
            }
        }

        if (! ($entitlement['active'] ?? false) || ! $this->entitlements->allows($entitlement, 'crm.access')) {
            return response()->json([
                'message' => 'An active Vista Express 360 subscription is required to use this CRM workspace.',
                'code' => 'vista360_subscription_required',
                'data' => ['entitlement' => $entitlement],
            ], 403);
        }
        $feature = $this->featureFor($request);
        if ($feature && ! $this->entitlements->allows($entitlement, $feature)) {
            return response()->json([
                'message' => 'This action is not included in the current Vista Express 360 plan.',
                'code' => 'vista360_plan_feature_required',
                'data' => ['feature' => $feature, 'entitlement' => $entitlement],
            ], 403);
        }
        $request->attributes->set('vista360_entitlement', $entitlement);

        return $next($request);
    }

    private function mayUseStale($snapshot): bool
    {
        $seconds = (int) config('vista.entitlement_max_stale_seconds', 0);
        return $seconds > 0 && $snapshot && $snapshot->checked_at && $snapshot->checked_at->gte(now()->subSeconds($seconds))
            && $snapshot->status === 'active' && ($snapshot->expires_at === null || $snapshot->expires_at->isFuture());
    }

    private function featureFor(Request $request): ?string
    {
        $controller = class_basename(explode('@', (string) $request->route()->getActionName())[0]);
        $method = $request->route()->getActionMethod();
        return match ($controller) {
            'CustomerController', 'CustomerImportController' => 'crm.customers',
            'OpticalController' => 'crm.optical',
            'AgendaController' => 'crm.agenda',
            'CatalogController', 'ProductImageController' => in_array($method, ['inventory', 'movements', 'move', 'reverse'], true) ? 'crm.stock' : 'crm.products',
            'OrderController', 'PaymentController' => 'crm.orders',
            'DocumentController', 'PrivateFileController' => 'crm.documents',
            // Every paid plan can reach the workspace dashboard. Detailed
            // sales reports and exports remain a separately configurable
            // premium feature.
            'ReportController' => in_array($method, ['sales', 'export'], true) ? 'crm.reports' : null,
            'RecordOutputController' => 'crm.reports',
            'CommunicationController' => 'crm.communications',
            'EmployeeController' => 'crm.team',
            'OperationsController', 'ReminderController' => 'crm.reminders',
            'VistaController' => 'crm.vista_publish',
            default => null,
        };
    }
}
