<?php

namespace Tests\Unit;

use App\DTOs\OrderItemDTO;
use App\Models\Inventory;
use App\Models\Warehouse;
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
        // P001 (no refrigeration). W01 at 5 km has 200 units — nearest.
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
        // P001: W01(5km)=200, W02(12km)=50, W03(25km)=300.
        // Request 250 → W01 gives 200, W02 gives 50 = complete.
        $result = $this->service->select([
            new OrderItemDTO('P001', 250),
        ]);

        $this->assertSame('complete', $result->status);
        $this->assertEmpty($result->unfulfilledItems);

        $allocated = collect($result->allocations)->flatMap(fn ($w) => $w->lines)->sum('allocatedQuantity');
        $this->assertSame(250, $allocated);

        $codes = collect($result->allocations)->pluck('warehouseCode')->all();
        $this->assertContains('W01', $codes);
        // W02 must cover the 50-unit spillover (12 km, nearest after W01)
        $this->assertContains('W02', $codes);
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

        $codes = collect($result->allocations)->pluck('warehouseCode')->all();
        $this->assertContains('W01', $codes);

        $refrigCodes  = ['W04', 'W07', 'W08'];
        $usedRefrig   = array_intersect($codes, $refrigCodes);
        $this->assertNotEmpty($usedRefrig, 'At least one refrigerated warehouse must be used for P002');
    }

    // ── TC-04: Refrigerated product fulfilled from eligible cold warehouse ─

    public function test_refrigerated_product_uses_cold_warehouse(): void
    {
        // P004 (Tetanus Toxoid, requires_refrigeration = true).
        // Eligible: W04 (25 units, 30 km), W07 (10 units, 45 km), W08 (50 units, 150 km).
        // Nearest = W04, quantity 20 ≤ 25, so W04 alone should supply it.
        $result = $this->service->select([
            new OrderItemDTO('P004', 20),
        ]);

        $this->assertSame('complete', $result->status);
        $this->assertCount(1, $result->allocations);
        $this->assertSame('W04', $result->allocations[0]->warehouseCode);

        $eligible = ['W04', 'W07', 'W08'];
        foreach (collect($result->allocations)->pluck('warehouseCode') as $code) {
            $this->assertContains($code, $eligible, "Warehouse {$code} must not supply P004");
        }
    }

    // ── TC-05: Refrigerated product not supplied by non-cold warehouses ───

    public function test_refrigerated_product_not_supplied_by_non_cold_warehouse(): void
    {
        // W02 (has cold storage but only standard vehicles) must NOT be used.
        // W06 (has cold storage but only standard vehicles) must NOT be used.
        $result = $this->service->select([
            new OrderItemDTO('P002', 10),
        ]);

        $this->assertSame('complete', $result->status);
        $usedCodes = collect($result->allocations)->pluck('warehouseCode')->all();

        foreach (['W01', 'W02', 'W03', 'W05', 'W06'] as $ineligible) {
            $this->assertNotContains($ineligible, $usedCodes,
                "Warehouse {$ineligible} must not supply a refrigerated product");
        }
    }

    // ── TC-05b: P004 cold-chain exclusion — W06 has P004 stock but no delivery vans

    public function test_p004_not_supplied_by_warehouse_without_refrigerated_delivery(): void
    {
        // W06 holds 30 units of P004 but has only standard vehicles → ineligible.
        // The result must only use W04, W07, or W08.
        $result = $this->service->select([
            new OrderItemDTO('P004', 10),
        ]);

        $this->assertSame('complete', $result->status);
        $usedCodes = collect($result->allocations)->pluck('warehouseCode')->all();

        $this->assertNotContains('W06', $usedCodes,
            'W06 has P004 stock but must not supply it (standard vehicles only)');
    }

    // ── TC-06: Sufficient total stock but no valid cold allocation ────────

    public function test_sufficient_total_stock_but_no_valid_cold_allocation(): void
    {
        // Zero out all refrigerated-delivery warehouse stock for P002.
        // W02 still has 30 units but cannot deliver cold → unfulfillable.
        Inventory::whereHas('warehouse', fn ($q) => $q->where('has_refrigerated_delivery', true))
            ->whereHas('product',   fn ($q) => $q->where('code', 'P002'))
            ->update(['quantity' => 0]);

        $result = $this->service->select([new OrderItemDTO('P002', 1)]);

        $this->assertSame('unfulfillable', $result->status);
        $this->assertNotEmpty($result->unfulfilledItems);
        $this->assertSame('P002', $result->unfulfilledItems[0]->productCode);
        $this->assertSame(1, $result->unfulfilledItems[0]->shortageQuantity);
    }

    // ── TC-07: Insufficient combined eligible stock ───────────────────────

    public function test_insufficient_combined_eligible_stock(): void
    {
        // Total eligible P002 stock (W04: 40 + W07: 8 + W08: 60) = 108.
        // Request 200 → shortage of 92.
        $result = $this->service->select([new OrderItemDTO('P002', 200)]);

        $this->assertSame('partial', $result->status);
        $this->assertNotEmpty($result->unfulfilledItems);
        $this->assertSame('P002', $result->unfulfilledItems[0]->productCode);
        $this->assertSame(92, $result->unfulfilledItems[0]->shortageQuantity);
        $this->assertSame(108, $result->unfulfilledItems[0]->allocatedQuantity);
    }

    // ── TC-09: Empty order array is trivially complete ────────────────────

    public function test_empty_order_is_trivially_complete_with_no_allocations(): void
    {
        // Service receives an empty array. Validation prevents this via HTTP,
        // but the service should handle it gracefully without crashing.
        $result = $this->service->select([]);

        // Nothing was requested → nothing is missing → trivially complete
        $this->assertSame('complete', $result->status);
        $this->assertEmpty($result->allocations);
        $this->assertEmpty($result->unfulfilledItems);
    }

    // ── TC-12: Empty inventory ────────────────────────────────────────────

    public function test_empty_inventory_returns_unfulfillable(): void
    {
        Inventory::query()->update(['quantity' => 0]);

        $result = $this->service->select([new OrderItemDTO('P001', 10)]);

        $this->assertSame('unfulfillable', $result->status);
        $this->assertEmpty($result->allocations);
        $this->assertNotEmpty($result->unfulfilledItems);
    }

    // ── TC-13: All five products in a single order ────────────────────────

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

        $allocatedCodes = collect($result->allocations)
            ->flatMap(fn ($w) => $w->lines)
            ->pluck('productCode')
            ->unique()->sort()->values()->all();

        $this->assertSame(['P001', 'P002', 'P003', 'P004', 'P005'], $allocatedCodes);
    }

    // ── TC-14: Deterministic results ─────────────────────────────────────

    public function test_deterministic_results_for_equivalent_requests(): void
    {
        $items   = [new OrderItemDTO('P001', 50), new OrderItemDTO('P002', 15)];
        $result1 = $this->service->select($items);
        $result2 = $this->service->select($items);

        $this->assertSame($result1->status, $result2->status);
        $this->assertEquals($result1->totalDistanceScore(), $result2->totalDistanceScore());

        $codes1 = collect($result1->allocations)->pluck('warehouseCode')->all();
        $codes2 = collect($result2->allocations)->pluck('warehouseCode')->all();
        $this->assertSame($codes1, $codes2);
    }

    // ── TC-15: Quantity conservation ─────────────────────────────────────

    public function test_allocations_never_exceed_requested_quantity(): void
    {
        $result = $this->service->select([new OrderItemDTO('P002', 30)]);

        $total = collect($result->allocations)->flatMap(fn ($w) => $w->lines)->sum('allocatedQuantity');
        $this->assertLessThanOrEqual(30, $total);
    }

    public function test_allocations_never_exceed_initial_stock(): void
    {
        // Total P001 stock: W01(200) + W02(50) + W03(300) + W05(400) + W07(150) = 1100
        $result = $this->service->select([new OrderItemDTO('P001', 1600)]);

        $allocated = collect($result->allocations)->flatMap(fn ($w) => $w->lines)->sum('allocatedQuantity');
        $this->assertLessThanOrEqual(1100, $allocated);
        $this->assertSame('partial', $result->status);
    }

    // ── TC-24: Shared-warehouse multi-product does not double-count stock ─

    public function test_shared_warehouse_multi_product_respects_virtual_inventory(): void
    {
        // W01 has P001=200, P003=500.
        // Order P001×200 + P003×500 should fully use W01's stock for both —
        // neither allocation should exceed what W01 actually has.
        $result = $this->service->select([
            new OrderItemDTO('P001', 200),
            new OrderItemDTO('P003', 500),
        ]);

        $this->assertSame('complete', $result->status);

        // Find W01's allocations
        $w01 = collect($result->allocations)->firstWhere('warehouseCode', 'W01');
        $this->assertNotNull($w01, 'W01 must participate in this order');

        $w01p001 = collect($w01->lines)->firstWhere('productCode', 'P001');
        $w01p003 = collect($w01->lines)->firstWhere('productCode', 'P003');

        // W01 has exactly 200 P001 — should take all 200
        $this->assertSame(200, $w01p001->allocatedQuantity);
        // W01 has exactly 500 P003 — should take all 500
        $this->assertSame(500, $w01p003->allocatedQuantity);
    }

    // ── TC-22: Allocations are sorted by distance ascending ──────────────

    public function test_allocations_sorted_by_distance_ascending(): void
    {
        // P001×1100 forces use of all five P001 warehouses.
        $result = $this->service->select([new OrderItemDTO('P001', 1100)]);

        $distances = collect($result->allocations)->pluck('distanceKm')->all();
        $sorted    = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances, 'Allocations must be sorted by distance ascending');
    }

    // ── TC-19: Explanations are grounded in actual data ───────────────────

    public function test_explanations_reference_actual_warehouse_codes(): void
    {
        $result = $this->service->select([
            new OrderItemDTO('P001', 50),
            new OrderItemDTO('P002', 10),
        ]);

        $this->assertNotEmpty($result->explanations);
        $allText = implode(' ', $result->explanations);
        $this->assertMatchesRegularExpression('/W\d{2}/', $allText);
    }

    public function test_explanation_cites_no_refrigerated_delivery_for_excluded_warehouse(): void
    {
        // Request enough P002 to trigger W04(40) + W07(8) — after those are exhausted,
        // explanations must mention why W02/W06 were excluded.
        $result = $this->service->select([new OrderItemDTO('P002', 5)]);

        $allText = implode(' ', $result->explanations);
        $this->assertMatchesRegularExpression(
            '/storage|refrigerat|cold/i',
            $allText,
            'Explanation must reference cold-chain constraint for P002'
        );
        // W02 and W06 have cold storage but no delivery vans — the reason
        // "no refrigerated delivery vehicles" must appear somewhere
        $this->assertStringContainsStringIgnoringCase('refrigerated delivery', $allText);
    }

    // ── Distance score calculation ────────────────────────────────────────

    public function test_distance_score_is_qty_times_distance(): void
    {
        // P001×100 from W01 (5 km) → score = 500
        $result = $this->service->select([new OrderItemDTO('P001', 100)]);

        $this->assertSame('complete', $result->status);
        $this->assertEquals(500, $result->totalDistanceScore());
    }

    public function test_distance_score_accumulates_across_warehouses(): void
    {
        // P001×250: W01(200 @ 5km) + W02(50 @ 12km).
        // Score = (200 × 5) + (50 × 12) = 1000 + 600 = 1600.
        $result = $this->service->select([new OrderItemDTO('P001', 250)]);

        $this->assertSame('complete', $result->status);
        $this->assertEquals(1600, $result->totalDistanceScore());
    }

    // ── Warehouse model unit test ─────────────────────────────────────────

    public function test_can_handle_refrigerated_requires_both_flags(): void
    {
        // W02: has cold storage, no refrigerated delivery → false
        $w02 = \App\Models\Warehouse::where('code', 'W02')->first();
        $this->assertFalse($w02->canHandleRefrigerated(),
            'W02 has cold storage but standard vehicles — must return false');

        // W04: both flags true → eligible
        $w04 = \App\Models\Warehouse::where('code', 'W04')->first();
        $this->assertTrue($w04->canHandleRefrigerated(),
            'W04 has both cold storage and refrigerated delivery — must return true');

        // W06: has cold storage, no refrigerated delivery → false
        $w06 = \App\Models\Warehouse::where('code', 'W06')->first();
        $this->assertFalse($w06->canHandleRefrigerated(),
            'W06 has cold storage but standard vehicles — must return false');

        // W01: neither flag → false
        $w01 = \App\Models\Warehouse::where('code', 'W01')->first();
        $this->assertFalse($w01->canHandleRefrigerated());
    }
}
