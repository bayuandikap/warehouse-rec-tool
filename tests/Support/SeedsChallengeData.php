<?php

namespace Tests\Support;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;

/**
 * Seeds the exact challenge dataset into the in-memory test database.
 * Use this trait in test classes that need reproducible fixture data.
 */
trait SeedsChallengeData
{
    protected function seedChallengeData(): void
    {
        // -- Products -------------------------------------------------------
        $products = [
            ['code' => 'P001', 'name' => 'Surgical Gloves (Box of 100)',          'category' => 'Medical Supplies', 'requires_refrigeration' => false],
            ['code' => 'P002', 'name' => 'Vaccine – Hepatitis B (10-dose vial)',  'category' => 'Pharmaceutical',   'requires_refrigeration' => true],
            ['code' => 'P003', 'name' => 'Disposable Syringe (Box of 50)',         'category' => 'Medical Supplies', 'requires_refrigeration' => false],
            ['code' => 'P004', 'name' => 'Vaccine – Tetanus Toxoid (10-dose vial)','category' => 'Pharmaceutical',   'requires_refrigeration' => true],
            ['code' => 'P005', 'name' => 'Paracetamol Tablets (Box of 100)',       'category' => 'Pharmaceutical',   'requires_refrigeration' => false],
        ];
        foreach ($products as $p) {
            Product::create($p);
        }

        // -- Warehouses -----------------------------------------------------
        $warehouses = [
            ['code' => 'W01', 'city' => 'Surabaya', 'distance_km' => 5,   'has_cold_storage' => false, 'has_refrigerated_delivery' => false],
            ['code' => 'W02', 'city' => 'Surabaya', 'distance_km' => 12,  'has_cold_storage' => true,  'has_refrigerated_delivery' => false],
            ['code' => 'W03', 'city' => 'Sidoarjo', 'distance_km' => 25,  'has_cold_storage' => false, 'has_refrigerated_delivery' => false],
            ['code' => 'W04', 'city' => 'Gresik',   'distance_km' => 30,  'has_cold_storage' => true,  'has_refrigerated_delivery' => true],
            ['code' => 'W05', 'city' => 'Malang',   'distance_km' => 90,  'has_cold_storage' => false, 'has_refrigerated_delivery' => false],
            ['code' => 'W06', 'city' => 'Malang',   'distance_km' => 95,  'has_cold_storage' => true,  'has_refrigerated_delivery' => false],
            ['code' => 'W07', 'city' => 'Pasuruan', 'distance_km' => 45,  'has_cold_storage' => true,  'has_refrigerated_delivery' => true],
            ['code' => 'W08', 'city' => 'Jember',   'distance_km' => 150, 'has_cold_storage' => true,  'has_refrigerated_delivery' => true],
        ];
        foreach ($warehouses as $w) {
            Warehouse::create($w);
        }

        // -- Inventory ------------------------------------------------------
        $data = [
            'W01' => ['P001' => 200, 'P002' => 0,  'P003' => 500, 'P004' => 0,  'P005' => 300],
            'W02' => ['P001' => 50,  'P002' => 30, 'P003' => 0,   'P004' => 20, 'P005' => 0],
            'W03' => ['P001' => 300, 'P002' => 0,  'P003' => 200, 'P004' => 0,  'P005' => 0],
            'W04' => ['P001' => 0,   'P002' => 40, 'P003' => 0,   'P004' => 25, 'P005' => 100],
            'W05' => ['P001' => 400, 'P002' => 0,  'P003' => 300, 'P004' => 0,  'P005' => 500],
            'W06' => ['P001' => 0,   'P002' => 20, 'P003' => 100, 'P004' => 30, 'P005' => 0],
            'W07' => ['P001' => 150, 'P002' => 8,  'P003' => 0,   'P004' => 10, 'P005' => 0],
            'W08' => ['P001' => 0,   'P002' => 60, 'P003' => 0,   'P004' => 50, 'P005' => 200],
        ];

        $wIds = Warehouse::pluck('id', 'code');
        $pIds = Product::pluck('id', 'code');

        foreach ($data as $wCode => $products) {
            foreach ($products as $pCode => $qty) {
                Inventory::create([
                    'warehouse_id' => $wIds[$wCode],
                    'product_id'   => $pIds[$pCode],
                    'quantity'     => $qty,
                ]);
            }
        }
    }
}
