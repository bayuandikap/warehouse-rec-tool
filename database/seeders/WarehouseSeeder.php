<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        // Assumption A2:
        //   has_cold_storage         = warehouse facility has refrigerated storage.
        //   has_refrigerated_delivery = warehouse has refrigerated vans for delivery.
        //
        // W02 and W06 have cold storage but "Standard vehicles" only →
        //   has_refrigerated_delivery = false.
        // W04, W07, W08 have "Refrigerated vans available" →
        //   has_refrigerated_delivery = true.
        //
        // Only warehouses where BOTH flags are true can supply refrigerated products.

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

        foreach ($warehouses as $warehouse) {
            Warehouse::updateOrCreate(['code' => $warehouse['code']], $warehouse);
        }
    }
}
