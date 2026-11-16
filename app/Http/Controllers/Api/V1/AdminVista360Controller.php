<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Vista\Vista360AdminBridgeClient;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native CRM Admin facade for Marketplace-owned Vista Express 360 plans.
 * Marketplace data stays in its source system; this controller only relays
 * authorised, signed administration requests server-to-server.
 */
class AdminVista360Controller extends Controller
{
    private function permit(Request $request, bool $write = false): void
    {
        abort_unless($request->user()->platformCan($write ? 'platform.audit' : 'platform.view'), 403);
    }

    public function plans(Request $request, Vista360AdminBridgeClient $bridge): JsonResponse
    {
        $this->permit($request);

        return $this->relay($bridge, 'GET', 'plans');
    }

    public function store(Request $request, Vista360AdminBridgeClient $bridge): JsonResponse
    {
        $this->permit($request, true);
        $data = $request->validate($this->planRules());

        return $this->relay($bridge, 'POST', 'plans', $data, 'vista360.plan.created');
    }

    public function update(Request $request, Vista360AdminBridgeClient $bridge, int $id): JsonResponse
    {
        $this->permit($request, true);
        $data = $request->validate($this->planRules());

        return $this->relay($bridge, 'PUT', 'plans/'.$id, $data, 'vista360.plan.updated', $id);
    }

    public function availability(Request $request, Vista360AdminBridgeClient $bridge, int $id): JsonResponse
    {
        $this->permit($request, true);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return $this->relay($bridge, 'POST', 'plans/'.$id.'/availability', $data, 'vista360.plan.availability_updated', $id);
    }

    public function subscriptions(Request $request, Vista360AdminBridgeClient $bridge): JsonResponse
    {
        $this->permit($request);
        $data = $request->validate(['status' => 'nullable|string|max:32', 'seller_id' => 'nullable|integer|min:1', 'store_id' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:120', 'per_page' => 'nullable|integer|min:1|max:100']);

        return $this->relay($bridge, 'GET', 'subscriptions'.($data ? '?'.http_build_query($data, '', '&', PHP_QUERY_RFC3986) : ''));
    }

    public function subscription(Request $request, Vista360AdminBridgeClient $bridge, string $uuid): JsonResponse
    {
        $this->permit($request);

        return $this->relay($bridge, 'GET', 'subscriptions/'.$uuid);
    }

    public function manualGrant(Request $request, Vista360AdminBridgeClient $bridge): JsonResponse
    {
        $this->permit($request, true);
        $data = $request->validate(['seller_id' => 'required|integer|min:1', 'store_id' => 'required|integer|min:1', 'plan_id' => 'required|integer|min:1', 'duration_months' => 'required|integer|in:1,6,12', 'reason' => 'nullable|string|max:1000']);

        return $this->relay($bridge, 'POST', 'subscriptions/manual-grant', $data, 'vista360.subscription.manual_grant');
    }

    public function action(Request $request, Vista360AdminBridgeClient $bridge, string $uuid): JsonResponse
    {
        $this->permit($request, true);
        $data = $request->validate(['action' => 'required|in:extend,activate,suspend,cancel', 'months' => 'required_if:action,extend|nullable|integer|in:1,6,12', 'reason' => 'nullable|string|max:1000']);

        return $this->relay($bridge, 'POST', 'subscriptions/'.$uuid.'/action', $data, 'vista360.subscription.'.$data['action']);
    }

    public function payments(Request $request, Vista360AdminBridgeClient $bridge): JsonResponse
    {
        $this->permit($request);
        $data = $request->validate(['status' => 'nullable|string|max:32', 'per_page' => 'nullable|integer|min:1|max:100']);

        return $this->relay($bridge, 'GET', 'payments'.($data ? '?'.http_build_query($data, '', '&', PHP_QUERY_RFC3986) : ''));
    }

    private function relay(Vista360AdminBridgeClient $bridge, string $method, string $path, array $data = [], ?string $auditAction = null, ?int $subjectId = null): JsonResponse
    {
        $remote = $bridge->send($method, $path, $data);
        if ($auditAction) {
            Audit::record($auditAction, null, request()->user()->id, 'vista360_marketplace', $subjectId);
        }

        return response()->json(['data' => $remote['data'], 'message' => $remote['message'] ?? null], $method === 'POST' && str_ends_with($path, 'plans') ? 201 : 200);
    }

    private function planRules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'description' => 'nullable|string|max:5000',
            'is_active' => 'required|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'display_details' => 'nullable|array',
            'prices' => 'required|array|min:1|max:3',
            'prices.*.duration_months' => 'required|integer|distinct|in:1,6,12',
            'prices.*.amount_cents' => 'required|integer|min:0|max:99999999',
            'prices.*.currency' => 'nullable|string|size:3',
            'prices.*.is_active' => 'nullable|boolean',
            'features' => 'present|array|max:100',
            'features.*.feature_key' => ['required', 'string', 'max:120', 'distinct', 'regex:/^[a-z0-9._-]+$/'],
            'features.*.title' => 'required|string|max:160',
            'features.*.description' => 'nullable|string|max:2000',
            'features.*.value' => 'nullable',
            'features.*.is_active' => 'nullable|boolean',
            'features.*.sort_order' => 'nullable|integer|min:0|max:100000',
        ];
    }
}
