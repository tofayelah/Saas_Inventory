<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\SecureStorageService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantApiController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected SecureStorageService $storageService
    ) {}

    /**
     * Inspect active token and tenant context.
     */
    public function tokenContext(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $tenant = $this->tenantContext->getTenant();

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'status' => $tenant->status,
            ] : null,
            'token' => $token ? [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'bound_team_id' => $token->team_id ?? null,
            ] : null,
        ]);
    }

    /**
     * List products belonging to the active tenant.
     */
    public function products(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::with(['category', 'brand'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($products);
    }

    /**
     * Create product for active tenant.
     */
    public function storeProduct(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100',
            'selling_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:product_categories,id',
            'brand_id' => 'nullable|exists:brands,id',
        ]);

        $product = Product::create($validated);

        return response()->json([
            'status' => 'success',
            'data' => $product,
        ], 201);
    }

    /**
     * List customers belonging to the active tenant.
     */
    public function customers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $customers = Customer::latest()->paginate($request->integer('per_page', 15));

        return response()->json($customers);
    }

    /**
     * List sales orders belonging to the active tenant.
     */
    public function salesOrders(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SalesOrder::class);

        $orders = SalesOrder::with('customer')->latest()->paginate($request->integer('per_page', 15));

        return response()->json($orders);
    }

    /**
     * Download a private file via API.
     */
    public function downloadFile(Request $request): BinaryFileResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $path = (string) $request->input('path', '');

        return $this->storageService->downloadTenantFile(
            teamId: $tenant->id,
            relativePath: $path,
            downloadName: $request->input('name')
        );
    }
}
