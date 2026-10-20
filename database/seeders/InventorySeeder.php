<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        // Initial inventory from challenge data.
        // Row = warehouse code, columns = [P001, P002, P003, P004, P005]
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

        // Pre-load all IDs to avoid N+1 queries
        $warehouseIds = Warehouse::pluck('id', 'code');
        $productIds   = Product::pluck('id', 'code');

        foreach ($data as $warehouseCode => $products) {
            $warehouseId = $warehouseIds[$warehouseCode];
            foreach ($products as $productCode => $qty) {
                Inventory::updateOrCreate(
                    [
                        'warehouse_id' => $warehouseId,
                        'product_id'   => $productIds[$productCode],
                    ],
                    ['quantity' => $qty]
                );
            }
        }
    }
}
