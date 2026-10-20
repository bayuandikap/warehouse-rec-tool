<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            // Assumption A1: Vaccines (P002, P004) require refrigeration.
            // Medical supplies (P001, P003) and Paracetamol (P005) do not.
            ['code' => 'P001', 'name' => 'Surgical Gloves (Box of 100)',     'category' => 'Medical Supplies', 'requires_refrigeration' => false],
            ['code' => 'P002', 'name' => 'Vaccine – Hepatitis B (10-dose vial)', 'category' => 'Pharmaceutical', 'requires_refrigeration' => true],
            ['code' => 'P003', 'name' => 'Disposable Syringe (Box of 50)',   'category' => 'Medical Supplies', 'requires_refrigeration' => false],
            ['code' => 'P004', 'name' => 'Vaccine – Tetanus Toxoid (10-dose vial)', 'category' => 'Pharmaceutical', 'requires_refrigeration' => true],
            ['code' => 'P005', 'name' => 'Paracetamol Tablets (Box of 100)', 'category' => 'Pharmaceutical', 'requires_refrigeration' => false],
        ];

        foreach ($products as $product) {
            Product::updateOrCreate(['code' => $product['code']], $product);
        }
    }
}
