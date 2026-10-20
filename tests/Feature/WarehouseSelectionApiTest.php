<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsChallengeData;
use Tests\TestCase;

/**
 * Feature tests for POST /api/warehouse-selection.
 *
 * Tests verify the HTTP layer (status codes, response structure) as well
 * as end-to-end business behaviour through the API.
 */
class WarehouseSelectionApiTest extends TestCase
{
    use RefreshDatabase, SeedsChallengeData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedChallengeData();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Happy path — valid requests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_valid_single_product_returns_200_complete(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 50],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'summary',
                    'allocations' => [
                        '*' => [
                            'warehouse_code',
                            'warehouse_city',
                            'distance_km',
                            'items' => [
                                '*' => [
                                    'product_code',
                                    'product_name',
                                    'requested_quantity',
                                    'allocated_quantity',
                                ],
                            ],
                            'warehouse_distance_score',
                        ],
                    ],
                    'unfulfilled_items',
                    'total_allocated_lines',
                    'total_distance_score',
                    'distance_score_note',
                    'explanations',
                ],
            ]);
    }

    public function test_multi_product_order_returns_200(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 100],
                ['product_id' => 'P002', 'quantity' => 20],
                ['product_id' => 'P003', 'quantity' => 200],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'complete');
    }

    public function test_all_five_products_returns_complete(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 10],
                ['product_id' => 'P002', 'quantity' => 10],
                ['product_id' => 'P003', 'quantity' => 10],
                ['product_id' => 'P004', 'quantity' => 10],
                ['product_id' => 'P005', 'quantity' => 10],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.unfulfilled_items', []);
    }

    public function test_partial_order_returns_200_partial_status(): void
    {
        // Request more P002 than total eligible stock (W04: 40 + W07: 8 + W08: 60 = 108)
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P002', 'quantity' => 200],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'partial');

        $unfulfilled = $response->json('data.unfulfilled_items');
        $this->assertNotEmpty($unfulfilled);
        $this->assertSame('P002', $unfulfilled[0]['product_code']);
    }

    public function test_unfulfillable_order_returns_200_unfulfillable_status(): void
    {
        // Zero out all inventory
        \App\Models\Inventory::query()->update(['quantity' => 0]);

        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 10],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'unfulfillable');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation errors — must return 422
    // ─────────────────────────────────────────────────────────────────────────

    /** TC-08: Unknown product ID */
    public function test_unknown_product_id_returns_422(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'ZZZZ', 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    /** TC-09: Missing items key */
    public function test_missing_items_returns_422(): void
    {
        $response = $this->postJson('/api/warehouse-selection', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /** TC-09: Empty items array */
    public function test_empty_items_array_returns_422(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /** TC-10: Zero quantity */
    public function test_zero_quantity_returns_422(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 0],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    /** TC-10: Negative quantity */
    public function test_negative_quantity_returns_422(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => -5],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    /** TC-10: Fractional quantity (not an integer) */
    public function test_fractional_quantity_returns_422(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 1.5],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    /** Over-limit quantity */
    public function test_quantity_exceeding_max_returns_422(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 10000],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    /** TC-11: Duplicate product lines */
    public function test_duplicate_product_lines_flagged(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 10],
                ['product_id' => 'P001', 'quantity' => 20],
            ],
        ]);

        // Duplicates are flagged as a validation error
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.product_id']);
    }

    /** Missing product_id field */
    public function test_missing_product_id_returns_422(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['quantity' => 10],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    /** Missing quantity field */
    public function test_missing_quantity_returns_422(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Business rule verification
    // ─────────────────────────────────────────────────────────────────────────

    /** TC-05: Refrigerated product must not use non-refrigerated warehouse */
    public function test_refrigerated_product_not_from_non_cold_warehouse(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P002', 'quantity' => 10],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'complete');

        $allocations = $response->json('data.allocations');
        $usedCodes = collect($allocations)->pluck('warehouse_code')->all();

        $ineligible = ['W01', 'W02', 'W03', 'W05', 'W06'];
        foreach ($ineligible as $code) {
            $this->assertNotContains(
                $code,
                $usedCodes,
                "Warehouse {$code} should not supply refrigerated product P002"
            );
        }
    }

    /** Distance score equals Σ(qty × distance) */
    public function test_distance_score_calculation_is_correct(): void
    {
        // Request P001×100 — should go to W01 (5 km) → score = 500
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 100],
            ],
        ]);

        $response->assertStatus(200);
        $score = $response->json('data.total_distance_score');
        // JSON decodes numbers as int or float depending on value;
        // use assertEquals for loose type comparison of numeric values.
        $this->assertEquals(500, $score);
    }

    /** Allocated quantity never exceeds requested quantity */
    public function test_allocated_quantity_does_not_exceed_requested(): void
    {
        $requested = 50;
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => $requested],
            ],
        ]);

        $response->assertStatus(200);
        $allocations = $response->json('data.allocations');
        $totalAllocated = collect($allocations)
            ->flatMap(fn ($w) => $w['items'])
            ->sum('allocated_quantity');

        $this->assertLessThanOrEqual($requested, $totalAllocated);
    }

    /** Shortage + allocated = requested for unfulfilled items */
    public function test_shortage_plus_allocated_equals_requested(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P002', 'quantity' => 200],
            ],
        ]);

        $response->assertStatus(200);
        foreach ($response->json('data.unfulfilled_items') as $item) {
            $this->assertSame(
                $item['requested_quantity'],
                $item['allocated_quantity'] + $item['shortage_quantity'],
                'shortage + allocated must equal requested'
            );
        }
    }

    /** Explanations are non-empty on a successful result */
    public function test_explanations_are_present_on_complete_result(): void
    {
        $response = $this->postJson('/api/warehouse-selection', [
            'items' => [
                ['product_id' => 'P001', 'quantity' => 10],
            ],
        ]);

        $response->assertStatus(200);
        $explanations = $response->json('data.explanations');
        $this->assertNotEmpty($explanations);
        $this->assertIsString($explanations[0]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Web interface
    // ─────────────────────────────────────────────────────────────────────────

    public function test_index_page_returns_200(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200)
            ->assertSee('Simpul')
            ->assertSee('P001');
    }
}
