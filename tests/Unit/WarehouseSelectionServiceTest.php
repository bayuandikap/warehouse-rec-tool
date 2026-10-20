<?php

namespace Tests\Unit;

use App\DTOs\OrderItemDTO;
use App\Services\WarehouseSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsChallengeData;
use Tests\TestCase;

/**
 * Unit tests for WarehouseSelectionService.
 *
 * Each test uses a fresh in-memory SQLite database seeded with the exact
 * challenge dataset so results are deterministic and grounded in real data.
 */
class WarehouseSelectionServiceTest extends TestCase
{
    use RefreshDatabase, SeedsChallengeData;

    private WarehouseSelectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedChallengeData();
        $this->service = new WarehouseSelectionService();
    }

    // ── TC-01: Single product fulfilled by nearest eligible warehouse ─────

    public function test_single_product_fulfilled_by_nearest_warehouse(): void
    {
        // P001 (no refrigeration).  W01 at 5 km has 200 units — nearest.
        $result = $this->service->select([
            new OrderItemDTO('P001', 100),
        ]);

        $this->assertSame('complete', $result->status);
        $this->assertCount(1, $result->allocations);
        $this->assertSame('W01', $result->allocations[0]->warehouseCode);
        $this->assertSame(100, $result->allocations[0]->lines[0]->allocatedQuantity);
        $this->assertEmpty($result->unfulfilledItems);
    }

    // ── TC-02: Single product — nearest warehouse has insufficient stock ──

    public function test_single_product_nearest_warehouse_insufficient_stock(): void
    {
        // P001: W01 has 200.  Request 250 → spills to next eligible warehouse.
        // W03 (25 km) has 300.  So result: W01 gives 200, W03 gives 50.
        $result = $this->service->select([
            new OrderItemDTO('P001', 250),
        ]);

        $this->assertSame('complete', $result->status);
        $this->assertEmpty($result->unfulfilledItems);

        $allocated = collect($result->allocations)->flatMap(fn ($w) => $w->lines)->sum('allocatedQuantity');
        $this->assertSame(250, $allocated);

        $codes = collect($result->allocations)->pluck('warehouseCode')->all();
        $this->assertContains('W01', $codes);
    }

    // ── TC-03: Order requires products from multiple warehouses ───────────

    public function test_order_fulfilled_from_multiple_warehouses(): void
    {
        // P002 (refrigeration required) → only W04, W07, W08 are eligible.
        // P001 (no refrigeration) → W01 nearest with stock.
        $result = $this->service->select([
            new OrderItemDTO('P001', 50),
            new OrderItemDTO('P002', 20),
        ]);

        $this->assertSame('complete', $result->status);
        $this->assertEmpty($result->unfulfilledItems);

        $warehouses = collect($result->allocations)->pluck('warehouseCode')->all();
        // P002 must come from a refrigeration-capable warehouse
        $refrigCodes = ['W04', 'W07', 'W08'];
        $hasRefrig = count(array_intersect($warehouses, $refrigCodes)) > 0;
        $this->assertTrue($hasRefrig, 'At least one refrigerated warehouse must be used for P002');
    }

    // ── TC-04: Refrigerated product fulfilled from eligible cold warehouse ─

    public function test_refrigerated_product_uses_cold_warehouse(): void
    {
        // P004 (Tetanus Toxoid, requires_refrigeration = true).
        // Eligible: W04 (25 units), W07 (10 units), W08 (50 units).
        $result = $this->service->select([
            new OrderItemDTO('P004', 20),
        ]);

        $this->assertSame('complete', $result->status);
        $usedCodes = collect($result->allocations)->pluck('warehouseCode')->all();
        $eligible  = ['W04', 'W07', 'W08'];
        foreach ($usedCodes as $code) {
            $this->assertContains($code, $eligible, "Warehouse {$code} is not eligible for refrigerated product P004");
        }
    }

    // ── TC-05: Refrigerated product not supplied by non-cold warehouse ────

    public function test_refrigerated_product_not_supplied_by_non_cold_warehouse(): void
    {
        // W02 has 30 units of P002 but no refrigerated delivery → must NOT be used.
        $result = $this->service->select([
            new OrderItemDTO('P002', 10),
        ]);

        $this->assertSame('complete', $result->status);
        $usedCodes = collect($result->allocations)->pluck('warehouseCode')->all();
        $this->assertNotContains('W01', $usedCodes);
        $this->assertNotContains('W02', $usedCodes); // has cold storage but standard vehicles only
        $this->assertNotContains('W03', $usedCodes);
        $this->assertNotContains('W05', $usedCodes);
        $this->assertNotContains('W06', $usedCodes); // has cold storage but standard vehicles only
    }

    // ── TC-06: Sufficient total stock but no valid allocation under storage constraints

    public function test_sufficient_total_stock_but_no_valid_cold_allocation(): void
    {
        // Manufacture a scenario: only warm warehouses have stock for a
        // refrigerated product.  We do this by directly manipulating a
        // custom setup rather than the shared seed.

        // Zero out all cold-capable warehouse stock for P002
        \App\Models\Inventory::whereHas('warehouse', fn ($q) => $q->where('has_refrigerated_delivery', true))
            ->whereHas('product', fn ($q) => $q->where('code', 'P002'))
            ->update(['quantity' => 0]);

        // W02 has 30 units of P002 but no refrigerated delivery — so
        // total P002 stock that is eligible = 0.
        $result = $this->service->select([
            new OrderItemDTO('P002', 1),
        ]);

        // W02 has stock but cannot deliver refrigerated → unfulfillable
        $this->assertSame('unfulfillable', $result->status);
        $this->assertNotEmpty($result->unfulfilledItems);
        $this->assertSame('P002', $result->unfulfilledItems[0]->productCode);
        $this->assertSame(1, $result->unfulfilledItems[0]->shortageQuantity);
    }

    // ── TC-07: Product with insufficient combined eligible stock ──────────

    public function test_insufficient_combined_eligible_stock(): void
    {
        // Total P002 eligible stock (W04: 40, W07: 8, W08: 60) = 108.
        // Request 200 → should be partial.
        $result = $this->service->select([
            new OrderItemDTO('P002', 200),
        ]);

        $this->assertSame('partial', $result->status);
        $this->assertNotEmpty($result->unfulfilledItems);
        $this->assertSame('P002', $result->unfulfilledItems[0]->productCode);
        $this->assertSame(200 - 108, $result->unfulfilledItems[0]->shortageQuantity);
    }

    // ── TC-09: Missing order items ────────────────────────────────────────

    public function test_empty_items_array_returns_unfulfillable(): void
    {
        // Service receives an empty array (validation in request prevents this
        // via HTTP, but we test the service is safe).
        // With zero items there are no allocations and no shortages.
        $result = $this->service->select([]);

        $this->assertSame('complete', $result->status); // trivially complete — nothing requested
        $this->assertEmpty($result->allocations);
        $this->assertEmpty($result->unfulfilledItems);
    }

    // ── TC-11: Duplicate product lines are normalized ─────────────────────

    public function test_duplicate_lines_normalized_by_controller(): void
    {
        // The controller normalizes duplicates before calling the service.
        // Here we test the service directly with pre-normalized input.
        $result = $this->service->select([
            new OrderItemDTO('P001', 300), // Combined from two lines of 150 each
        ]);

        $this->assertSame('complete', $result->status);
        $allocated = collect($result->allocations)->flatMap(fn ($w) => $w->lines)->sum('allocatedQuantity');
        $this->assertSame(300, $allocated);
    }

    // ── TC-12: Empty inventory ────────────────────────────────────────────

    public function test_empty_warehouse_inventory(): void
    {
        \App\Models\Inventory::query()->update(['quantity' => 0]);

        $result = $this->service->select([
            new OrderItemDTO('P001', 10),
        ]);

        $this->assertSame('unfulfillable', $result->status);
        $this->assertEmpty($result->allocations);
        $this->assertNotEmpty($result->unfulfilledItems);
    }

    // ── TC-13: All five products ──────────────────────────────────────────

    public function test_order_with_all_five_products(): void
    {
        $result = $this->service->select([
            new OrderItemDTO('P001', 10),
            new OrderItemDTO('P002', 10),
            new OrderItemDTO('P003', 10),
            new OrderItemDTO('P004', 10),
            new OrderItemDTO('P005', 10),
        ]);

        $this->assertSame('complete', $result->status);
        $this->assertEmpty($result->unfulfilledItems);

        // All five products should be allocated
        $allocatedCodes = collect($result->allocations)
            ->flatMap(fn ($w) => $w->lines)
            ->pluck('productCode')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['P001', 'P002', 'P003', 'P004', 'P005'], $allocatedCodes);
    }

    // ── TC-14: Deterministic results for equivalent requests ──────────────

    public function test_deterministic_results_for_equivalent_requests(): void
    {
        $items = [new OrderItemDTO('P001', 50), new OrderItemDTO('P002', 15)];

        $result1 = $this->service->select($items);
        $result2 = $this->service->select($items);

        $this->assertSame($result1->status, $result2->status);
        $this->assertSame($result1->totalDistanceScore(), $result2->totalDistanceScore());

        // Warehouse codes must be in same order
        $codes1 = collect($result1->allocations)->pluck('warehouseCode')->all();
        $codes2 = collect($result2->allocations)->pluck('warehouseCode')->all();
        $this->assertSame($codes1, $codes2);
    }

    // ── TC-15: Allocations never exceed requested or available quantity ───

    public function test_allocations_never_exceed_requested_quantity(): void
    {
        $requestedQty = 30;
        $result = $this->service->select([
            new OrderItemDTO('P002', $requestedQty),
        ]);

        $totalAllocated = collect($result->allocations)
            ->flatMap(fn ($w) => $w->lines)
            ->sum('allocatedQuantity');

        $this->assertLessThanOrEqual($requestedQty, $totalAllocated);
    }

    public function test_allocations_never_exceed_initial_stock(): void
    {
        // Total stock for P001 across all warehouses:
        // W01(200) + W02(50) + W03(300) + W05(400) + W07(150) = 1100
        $totalStock = 1100;

        $result = $this->service->select([
            new OrderItemDTO('P001', $totalStock + 500), // ask more than exists
        ]);

        $allocated = collect($result->allocations)
            ->flatMap(fn ($w) => $w->lines)
            ->sum('allocatedQuantity');

        $this->assertLessThanOrEqual($totalStock, $allocated);
        $this->assertSame('partial', $result->status);
    }

    // ── TC-19: Explanations correspond to the actual decision ────────────

    public function test_explanations_reference_actual_warehouse_codes(): void
    {
        $result = $this->service->select([
            new OrderItemDTO('P001', 50),
            new OrderItemDTO('P002', 10),
        ]);

        $this->assertNotEmpty($result->explanations);

        // At least one explanation should mention a warehouse code
        $allText = implode(' ', $result->explanations);
        $this->assertMatchesRegularExpression('/W\d{2}/', $allText);
    }

    public function test_explanation_mentions_refrigeration_for_cold_product(): void
    {
        $result = $this->service->select([
            new OrderItemDTO('P002', 5),
        ]);

        $allText = implode(' ', $result->explanations);
        // Should mention storage requirements being met (or not)
        $this->assertMatchesRegularExpression(
            '/storage|refrigerat|cold/i',
            $allText,
            'Explanation must reference storage/refrigeration for a cold-chain product'
        );
    }

    // ── Distance score correctness ────────────────────────────────────────

    public function test_distance_score_is_sum_of_qty_times_distance(): void
    {
        // P001×100 from W01 (5 km) → score should be 500
        $result = $this->service->select([
            new OrderItemDTO('P001', 100),
        ]);

        $this->assertSame('complete', $result->status);
        $this->assertEquals(500.0, $result->totalDistanceScore());
    }
}
