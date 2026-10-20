<?php

namespace App\DTOs;

/**
 * All product lines allocated to a single warehouse in this order.
 */
readonly class WarehouseAllocationDTO
{
    /**
     * @param AllocationLineDTO[] $lines
     */
    public function __construct(
        public string $warehouseCode,
        public string $warehouseCity,
        public float  $distanceKm,
        public array  $lines,
    ) {}

    /**
     * Sum of (allocatedQuantity × distanceKm) for all lines in this warehouse.
     * This is a routing-cost proxy, not a real shipping price.
     */
    public function distanceScore(): float
    {
        return array_reduce(
            $this->lines,
            fn (float $carry, AllocationLineDTO $line) => $carry + ($line->allocatedQuantity * $this->distanceKm),
            0.0
        );
    }
}
