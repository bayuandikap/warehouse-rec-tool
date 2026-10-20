<?php

namespace App\DTOs;

/**
 * Represents a single line in a customer order.
 */
readonly class OrderItemDTO
{
    public function __construct(
        public string $productCode,
        public int    $quantity,
    ) {}
}
