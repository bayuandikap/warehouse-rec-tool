<?php

use App\Http\Controllers\WarehouseSelectionController;
use Illuminate\Support\Facades\Route;

Route::post('/warehouse-selection', [WarehouseSelectionController::class, 'select'])
    ->name('api.warehouse-selection');
