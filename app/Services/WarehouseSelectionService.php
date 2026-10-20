<?php

namespace App\Services;

use App\DTOs\AllocationLineDTO;
use App\DTOs\OrderItemDTO;
use App\DTOs\SelectionResultDTO;
use App\DTOs\UnfulfilledItemDTO;
use App\DTOs\WarehouseAllocationDTO;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Collection;

/**
 * WarehouseSelectionService
 *
 * Given a normalized list of order items (product code + quantity), this
 * service determines the best warehouse or combination of warehouses to
 * fulfill the order.
 *
 * --- Design decisions ---
 *
 * This is a SIMULATION.  No inventory is modified.  The result is an
 * advisory allocation plan.
 *
 * Optimization objective (documented):
 *   Minimize Σ(allocated_quantity × warehouse_distance_km) subject to:
 *     1. Each product is allocated only from warehouses with sufficient stock.
 *     2. Products that require refrigeration are allocated only from warehouses
 *        that have BOTH cold storage AND refrigerated delivery capability.
 *     3. Each inventory unit is counted at most once per order.
 *
 * Strategy:
 *   For each product we build a sorted list of eligible warehouses (nearest
 *   first).  We then greedily assign as much as possible from the nearest
 *   eligible warehouse, spilling over to the next nearest when stock runs
 *   out.  This greedy approach is optimal for our objective when warehouse
 *   eligibility sets do not overlap in a way that would require
 *   combinatorial lookahead (which is not the case with 8 warehouses and
 *   5 products).  The algorithm is O(P × W) where P = products and W =
 *   warehouses — entirely tractable.
 *
 * The "virtual inventory" map tracks remaining stock across all products so
 * that the same unit is never allocated twice even when multiple products
 * are served by the same warehouse.
 */
class WarehouseSelectionService
{
    // -----------------------------------------------------------------------
    // Public entry point
    // -----------------------------------------------------------------------

    /**
     * @param  OrderItemDTO[]  $items  Already validated, normalized order lines.
     * @return SelectionResultDTO
     */
    public function select(array $items): SelectionResultDTO
    {
        // 1. Load all required data in three queries (products, warehouses,
        //    inventories).  We intentionally avoid per-product or per-warehouse
        //    queries inside loops.
        [$products, $warehouses, $inventoryMap] = $this->loadData();

        // 2. Build a virtual inventory: a mutable copy we drain as we allocate.
        //    Structure: ['W01' => ['P001' => 200, ...], ...]
        $virtualInventory = $this->buildVirtualInventory($inventoryMap);

        // 3. Allocate each product, building up the result structures.
        //    We collect allocations keyed by warehouse code so lines from the
        //    same warehouse are grouped together.
        /** @var array<string, array{warehouse: Warehouse, lines: AllocationLineDTO[]}> $warehouseGroups */
        $warehouseGroups  = [];
        $unfulfilledItems = [];
        $explanations     = [];

        foreach ($items as $item) {
            $product = $products->firstWhere('code', $item->productCode);

            [$warehouseGroups, $shortage, $itemExplanations] = $this->allocateProduct(
                item:             $item,
                product:          $product,
                warehouses:       $warehouses,
                virtualInventory: $virtualInventory,
                warehouseGroups:  $warehouseGroups,
            );

            $explanations = array_merge($explanations, $itemExplanations);

            if ($shortage > 0) {
                $unfulfilledItems[] = $this->buildUnfulfilledItem(
                    item:      $item,
                    product:   $product,
                    allocated: $item->quantity - $shortage,
                    shortage:  $shortage,
                    warehouses: $warehouses,
                );
            }
        }

        // 4. Convert the grouped structure to DTOs.
        $allocationDTOs = $this->buildAllocationDTOs($warehouseGroups);

        // 5. Derive overall status.
        $status  = $this->deriveStatus($items, $unfulfilledItems);
        $summary = $this->buildSummary($status, $unfulfilledItems);

        return new SelectionResultDTO(
            status:           $status,
            summary:          $summary,
            allocations:      $allocationDTOs,
            unfulfilledItems: $unfulfilledItems,
            explanations:     $explanations,
        );
    }

    // -----------------------------------------------------------------------
    // Data loading
    // -----------------------------------------------------------------------

    /**
     * Load all products, warehouses, and inventories in exactly three queries.
     * Only non-zero inventory rows are loaded — zero-stock rows contribute
     * nothing to allocation and would needlessly bloat the virtual inventory map.
     *
     * @return array{Collection, Collection, Collection}
     */
    private function loadData(): array
    {
        $products    = Product::select(['id', 'code', 'name', 'category', 'requires_refrigeration'])->get();
        $warehouses  = Warehouse::select(['id', 'code', 'city', 'distance_km', 'has_cold_storage', 'has_refrigerated_delivery'])
                                ->orderBy('distance_km')
                                ->get();
        $inventories = Inventory::select(['warehouse_id', 'product_id', 'quantity'])
                                ->where('quantity', '>', 0)
                                ->get();

        return [$products, $warehouses, $inventories];
    }

    /**
     * Build an in-memory inventory map we can drain safely during allocation.
     *
     * @param  Collection  $inventories
     * @return array<int, array<int, int>>  [warehouseId][productId] => quantity
     */
    private function buildVirtualInventory(Collection $inventories): array
    {
        $map = [];
        foreach ($inventories as $inv) {
            $map[$inv->warehouse_id][$inv->product_id] = $inv->quantity;
        }
        return $map;
    }

    // -----------------------------------------------------------------------
    // Product allocation
    // -----------------------------------------------------------------------

    /**
     * Allocate a single product across eligible warehouses (nearest-first).
     *
     * Returns the updated warehouse groups, the remaining shortage, and
     * human-readable explanation lines.
     *
     * @param  array<string, mixed>  $warehouseGroups  Pass-by-reference-style via return
     * @param  array<int, array<int, int>>  $virtualInventory  Mutated in place
     */
    private function allocateProduct(
        OrderItemDTO $item,
        Product      $product,
        Collection   $warehouses,
        array        &$virtualInventory,
        array        $warehouseGroups,
    ): array {
        $remaining    = $item->quantity;
        $explanations = [];
        $eligible     = [];
        $ineligible   = [];

        // Iterate warehouses sorted nearest-first (already sorted from loadData)
        foreach ($warehouses as $warehouse) {
            $available = $virtualInventory[$warehouse->id][$product->id] ?? 0;

            // Check eligibility
            if (! $this->isEligible($warehouse, $product, $available)) {
                $ineligible[] = $this->ineligibilityReason($warehouse, $product, $available);
                continue;
            }

            $eligible[] = $warehouse->code;

            if ($remaining <= 0) {
                continue;
            }

            // How much can this warehouse contribute?
            $take = min($available, $remaining);

            // Drain the virtual inventory
            $virtualInventory[$warehouse->id][$product->id] -= $take;
            $remaining -= $take;

            // Record the allocation line (only the warehouse's contribution,
            // not the full order quantity — see AllocationLineDTO docblock).
            $line = new AllocationLineDTO(
                productCode:       $product->code,
                productName:       $product->name,
                allocatedQuantity: $take,
            );

            if (! isset($warehouseGroups[$warehouse->code])) {
                $warehouseGroups[$warehouse->code] = [
                    'warehouse' => $warehouse,
                    'lines'     => [],
                ];
            }
            $warehouseGroups[$warehouse->code]['lines'][] = $line;

            $explanation = sprintf(
                '%s (%.0f km) will supply %d unit(s) of %s: has sufficient stock (%d available) and meets storage requirements.',
                $warehouse->code,
                $warehouse->distance_km,
                $take,
                $product->name,
                $available,
            );
            if ($take < $item->quantity) {
                $explanation .= sprintf(' Partial supply — only %d unit(s) available here.', $available);
            }
            $explanations[] = $explanation;
        }

        // Explain why no eligible warehouse could cover the remainder
        if ($remaining > 0 && count($eligible) === 0) {
            if ($product->requires_refrigeration) {
                $explanations[] = sprintf(
                    '%s requires cold-chain delivery. No eligible warehouse (needs both cold storage AND refrigerated delivery) has any stock.',
                    $product->name,
                );
            } else {
                $explanations[] = sprintf(
                    '%s: no warehouse has any stock available.',
                    $product->name,
                );
            }
        } elseif ($remaining > 0) {
            $explanations[] = sprintf(
                '%s: %d unit(s) remain unallocated — total eligible stock across all qualifying warehouses is insufficient.',
                $product->name,
                $remaining,
            );
        }

        // Summarize ineligible warehouses (deduplicated by reason)
        $ineligibleSummary = $this->summarizeIneligible($ineligible, $product, $warehouses);
        if ($ineligibleSummary !== '') {
            $explanations[] = $ineligibleSummary;
        }

        return [$warehouseGroups, $remaining, $explanations];
    }

    // -----------------------------------------------------------------------
    // Eligibility helpers
    // -----------------------------------------------------------------------

    /**
     * A warehouse is eligible to supply a product if:
     *  1. It has at least 1 unit of the product in stock.
     *  2. If the product requires refrigeration, the warehouse must have BOTH
     *     cold storage AND refrigerated delivery capability.
     */
    private function isEligible(Warehouse $warehouse, Product $product, int $available): bool
    {
        if ($available <= 0) {
            return false;
        }

        if ($product->requires_refrigeration && ! $warehouse->canHandleRefrigerated()) {
            return false;
        }

        return true;
    }

    /**
     * Return a short human-readable reason string for why a warehouse is
     * ineligible for a given product.  Used in explanation generation.
     */
    private function ineligibilityReason(Warehouse $warehouse, Product $product, int $available): string
    {
        if ($available <= 0 && $product->requires_refrigeration && ! $warehouse->canHandleRefrigerated()) {
            return "no stock and missing cold-chain capability";
        }

        if ($available <= 0) {
            return "no stock";
        }

        if ($product->requires_refrigeration && ! $warehouse->has_cold_storage) {
            return "no cold storage";
        }

        if ($product->requires_refrigeration && ! $warehouse->has_refrigerated_delivery) {
            return "no refrigerated delivery vehicles";
        }

        return "ineligible";
    }

    /**
     * Build a single concise explanation sentence for warehouses that were
     * skipped, grouped by reason.
     */
    private function summarizeIneligible(array $reasons, Product $product, Collection $warehouses): string
    {
        if (empty($reasons)) {
            return '';
        }

        $counts = array_count_values($reasons);
        $parts  = [];
        foreach ($counts as $reason => $count) {
            $parts[] = "{$count} warehouse(s) excluded: {$reason}";
        }

        return sprintf('For %s: %s.', $product->name, implode('; ', $parts));
    }

    // -----------------------------------------------------------------------
    // Result building
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $warehouseGroups */
    private function buildAllocationDTOs(array $warehouseGroups): array
    {
        $dtos = [];
        foreach ($warehouseGroups as $group) {
            /** @var Warehouse $warehouse */
            $warehouse = $group['warehouse'];
            $dtos[] = new WarehouseAllocationDTO(
                warehouseCode: $warehouse->code,
                warehouseCity: $warehouse->city,
                distanceKm:    $warehouse->distance_km,
                lines:         $group['lines'],
            );
        }

        // Sort by distance for a consistent, readable output
        usort($dtos, fn (WarehouseAllocationDTO $a, WarehouseAllocationDTO $b) => $a->distanceKm <=> $b->distanceKm);

        return $dtos;
    }

    private function buildUnfulfilledItem(
        OrderItemDTO $item,
        Product      $product,
        int          $allocated,
        int          $shortage,
        Collection   $warehouses,
    ): UnfulfilledItemDTO {
        // Determine the most accurate reason
        if ($product->requires_refrigeration) {
            $eligibleRefrigerated = $warehouses->filter(fn (Warehouse $w) => $w->canHandleRefrigerated());
            if ($eligibleRefrigerated->isEmpty()) {
                $reason = 'No warehouse has cold storage AND refrigerated delivery capability.';
            } else {
                $reason = 'Combined eligible refrigerated-warehouse stock is less than requested quantity.';
            }
        } else {
            $reason = 'Combined stock across all warehouses is less than requested quantity.';
        }

        return new UnfulfilledItemDTO(
            productCode:       $product->code,
            productName:       $product->name,
            requestedQuantity: $item->quantity,
            allocatedQuantity: $allocated,
            shortageQuantity:  $shortage,
            reason:            $reason,
        );
    }

    /**
     * @param OrderItemDTO[]      $items
     * @param UnfulfilledItemDTO[] $unfulfilledItems
     */
    private function deriveStatus(array $items, array $unfulfilledItems): string
    {
        if (empty($unfulfilledItems)) {
            return 'complete';
        }

        // If every requested item has a shortage, check whether any were
        // partially filled.  If none were, the order is entirely unfulfillable.
        if (count($unfulfilledItems) === count($items)) {
            foreach ($unfulfilledItems as $u) {
                if ($u->allocatedQuantity > 0) {
                    return 'partial';
                }
            }
            return 'unfulfillable';
        }

        return 'partial';
    }

    /** @param UnfulfilledItemDTO[] $unfulfilledItems */
    private function buildSummary(string $status, array $unfulfilledItems): string
    {
        return match ($status) {
            'complete'      => 'The order can be fulfilled completely from the selected warehouse(s).',
            'partial'       => sprintf(
                'The order can only be partially fulfilled. %d product line(s) have insufficient stock: %s.',
                count($unfulfilledItems),
                implode(', ', array_map(fn (UnfulfilledItemDTO $u) => $u->productName, $unfulfilledItems))
            ),
            'unfulfillable' => 'The order cannot be fulfilled. No eligible warehouse has sufficient stock for any requested product.',
            default         => 'Selection result unknown.',
        };
    }
}
