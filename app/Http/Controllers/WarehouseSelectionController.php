<?php

namespace App\Http\Controllers;

use App\DTOs\OrderItemDTO;
use App\Http\Requests\WarehouseSelectionRequest;
use App\Http\Resources\SelectionResultResource;
use App\Models\Product;
use App\Services\WarehouseSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $products = Product::orderBy('code')->get();
        return view('warehouse-selection.index', compact('products'));
    }

    // -----------------------------------------------------------------------
    // API endpoint
    // -----------------------------------------------------------------------

    /**
     * POST /api/warehouse-selection
     *
     * Accepts a JSON body with an "items" array.
     * Returns a 200 with a complete, partial, or unfulfillable decision.
     * Returns a 422 for invalid input.
     */
    public function select(WarehouseSelectionRequest $request): JsonResponse
    {
        $orderItems = $this->normalizeItems($request->validated()['items']);

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
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Convert raw validated input into OrderItemDTOs.
     * Duplicate product lines are merged by summing their quantities.
     *
     * @param  array<int, array{product_id: string, quantity: int}>  $rawItems
     * @return OrderItemDTO[]
     */
    private function normalizeItems(array $rawItems): array
    {
        $merged = [];
        foreach ($rawItems as $raw) {
            $code = $raw['product_id'];
            $qty  = (int) $raw['quantity'];
            if (isset($merged[$code])) {
                $merged[$code] += $qty;
            } else {
                $merged[$code] = $qty;
            }
        }

        return array_map(
            fn (string $code, int $qty) => new OrderItemDTO($code, $qty),
            array_keys($merged),
            array_values($merged),
        );
    }
}
