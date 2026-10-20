<?php

namespace App\Http\Controllers;

use App\DTOs\OrderItemDTO;
use App\Http\Requests\WarehouseSelectionRequest;
use App\Http\Resources\SelectionResultResource;
use App\Models\Product;
use App\Services\WarehouseSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class WarehouseSelectionController extends Controller
{
    public function __construct(
        private readonly WarehouseSelectionService $selectionService,
    ) {}

    // -----------------------------------------------------------------------
    // Web (Blade) entry point
    // -----------------------------------------------------------------------

    public function index(): View
    {
        // Only two fields needed by the view — avoid hydrating unused columns.
        $products = Product::select(['code', 'name'])->orderBy('code')->get();

        return view('warehouse-selection.index', compact('products'));
    }

    // -----------------------------------------------------------------------
    // API endpoint  POST /api/warehouse-selection
    // -----------------------------------------------------------------------

    /**
     * Accepts a validated order, runs the warehouse-selection simulation,
     * and returns a 200 with a complete/partial/unfulfillable recommendation.
     * Returns 422 for invalid input, 500 for unexpected server errors.
     * No inventory is modified — this is a read-only advisory operation.
     */
    public function select(WarehouseSelectionRequest $request): JsonResponse
    {
        // Validation (product existence, quantities, duplicates) is handled
        // entirely by WarehouseSelectionRequest.  By the time we reach here,
        // the input is clean and contains no unknown or duplicate product codes.
        $orderItems = $this->buildOrderItems($request->validated()['items']);

        try {
            $result = $this->selectionService->select($orderItems);
        } catch (\Throwable $e) {
            Log::error('WarehouseSelectionService error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error'   => 'An unexpected error occurred while processing the order.',
                'message' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 500);
        }

        return (new SelectionResultResource($result))
            ->response()
            ->setStatusCode(200);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Convert validated raw input into typed OrderItemDTOs.
     * Input is already free of duplicates (rejected at validation layer).
     *
     * @param  array<int, array{product_id: string, quantity: int}>  $rawItems
     * @return OrderItemDTO[]
     */
    private function buildOrderItems(array $rawItems): array
    {
        return array_map(
            fn (array $raw) => new OrderItemDTO($raw['product_id'], (int) $raw['quantity']),
            $rawItems,
        );
    }
}
