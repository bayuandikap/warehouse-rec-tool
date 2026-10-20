<?php

use App\Http\Controllers\WarehouseSelectionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WarehouseSelectionController::class, 'index'])->name('warehouse-selection.index');
