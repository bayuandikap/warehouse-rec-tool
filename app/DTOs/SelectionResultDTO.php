<?php

namespace App\DTOs;

/**
 * The complete result of a warehouse-selection run.
 *
 * status:
 *   'complete'      – every requested item is fully allocated.
 *   'partial'       – some items are allocated but at least one has a shortage.
 *   'unfulfillable' – no items could be allocated at all.
 */
readonly class SelectionResultDTO
{
    /**
     * @param WarehouseAllocationDTO[] $allocations
     * @param UnfulfilledItemDTO[]     $unfulfilledItems
     * @param string[]                 $explanations
     */
    public function __construct(
        public string $status,
        public string $summary,
        public array  $allocations,
        public array  $unfulfilledItems,
        public array  $explanations,
    ) {}

    public function totalAllocatedItems(): int
    {
        return array_reduce(
            $this->allocations,
            fn (int $carry, WarehouseAllocationDTO $w) => $carry + count($w->lines),
            0
        );
    }

    /**
     * Total routing-cost proxy: Σ(allocated_qty × warehouse_distance_km).
     */
    public function totalDistanceScore(): float
    {
        return array_reduce(
            $this->allocations,
            fn (float $carry, WarehouseAllocationDTO $w) => $carry + $w->distanceScore(),
            0.0
        );
    }
}
