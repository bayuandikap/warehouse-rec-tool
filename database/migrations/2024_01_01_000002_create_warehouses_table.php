<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique()->comment('Unique warehouse code, e.g. W01');
            $table->string('city', 100);
            $table->decimal('distance_km', 8, 2)->comment('Distance from customer hub in km');
            $table->boolean('has_cold_storage')->default(false)
                ->comment('Whether the warehouse facility has cold-chain storage capability');
            $table->boolean('has_refrigerated_delivery')->default(false)
                ->comment('Whether the warehouse has refrigerated vehicles for delivery');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
