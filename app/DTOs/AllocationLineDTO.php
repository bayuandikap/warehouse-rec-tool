<?php

namespace App\DTOs;

/**
 * A single product allocation from a specific warehouse.
 */
readonly class AllocationLineDTO
{
    public function __construct(
        public string $productCode,
        public string $productName,
        public int    $requestedQuantity,
        public int    $allocatedQuantity,
    ) {}
}
