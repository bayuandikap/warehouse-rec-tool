<?php

namespace App\DTOs;

/**
 * Describes a product that could not be fully allocated.
 */
readonly class UnfulfilledItemDTO
{
    public function __construct(
        public string $productCode,
        public string $productName,
        public int    $requestedQuantity,
        public int    $allocatedQuantity,
        public int    $shortageQuantity,
        public string $reason,
    ) {}
}
