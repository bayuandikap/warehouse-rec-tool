<?php

namespace App\DTOs;

/**
 * A single product allocation from a specific warehouse.
 *
 * Note: requestedQuantity is intentionally absent here.  When an order is
 * split across multiple warehouses, each AllocationLine carries only what
 * that warehouse contributes.  The total requested quantity lives on the
 * OrderItemDTO and is surfaced in unfulfilled_items when there is a shortage.
 */
readonly class AllocationLineDTO
{
    public function __construct(
        public string $productCode,
        public string $productName,
        public int    $allocatedQuantity,
    ) {}
}
