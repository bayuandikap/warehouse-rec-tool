<?php

namespace App\Http\Resources;

use App\DTOs\AllocationLineDTO;
use App\DTOs\SelectionResultDTO;
use App\DTOs\UnfulfilledItemDTO;
use App\DTOs\WarehouseAllocationDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a SelectionResultDTO into the documented JSON response shape.
 *
 * Note: JsonResource normally wraps an Eloquent model.  Here we extend it
 * to wrap our DTO because it gives us the standard Laravel resource envelope
 * and keeps the controller thin.
 *
 * @property SelectionResultDTO $resource
 */
class SelectionResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SelectionResultDTO $result */
        $result = $this->resource;

        return [
            'status'               => $result->status,
            'summary'              => $result->summary,
            'allocations'          => array_map(
                fn (WarehouseAllocationDTO $w) => [
                    'warehouse_code' => $w->warehouseCode,
                    'warehouse_city' => $w->warehouseCity,
                    'distance_km'    => $w->distanceKm,
                    'items'          => array_map(
                        fn (AllocationLineDTO $l) => [
                            'product_code'      => $l->productCode,
                            'product_name'      => $l->productName,
                            'allocated_quantity'=> $l->allocatedQuantity,
                        ],
                        $w->lines
                    ),
                    'warehouse_distance_score' => $w->distanceScore(),
                ],
                $result->allocations
            ),
            'unfulfilled_items'    => array_map(
                fn (UnfulfilledItemDTO $u) => [
                    'product_code'      => $u->productCode,
                    'product_name'      => $u->productName,
                    'requested_quantity'=> $u->requestedQuantity,
                    'allocated_quantity'=> $u->allocatedQuantity,
                    'shortage_quantity' => $u->shortageQuantity,
                    'reason'            => $u->reason,
                ],
                $result->unfulfilledItems
            ),
            'total_allocated_lines'  => $result->totalAllocatedItems(),
            'total_distance_score'   => $result->totalDistanceScore(),
            'distance_score_note'    => 'Sum of (allocated_quantity × warehouse_distance_km). A routing-cost proxy, not an actual shipping price.',
            'explanations'           => $result->explanations,
        ];
    }
}
