<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique()->comment('Unique product code, e.g. P001');
            $table->string('name');
            $table->string('category', 100);
            $table->boolean('requires_refrigeration')->default(false)
                ->comment('True if this product needs cold-chain storage and refrigerated delivery');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
