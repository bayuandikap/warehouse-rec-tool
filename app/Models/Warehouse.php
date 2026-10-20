<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'code',
        'city',
        'distance_km',
        'has_cold_storage',
        'has_refrigerated_delivery',
    ];

    protected $casts = [
        'distance_km'              => 'float',
        'has_cold_storage'         => 'boolean',
        'has_refrigerated_delivery'=> 'boolean',
    ];

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    // -----------------------------------------------------------------------
    // Business helpers
    // -----------------------------------------------------------------------

    /**
     * Return true when this warehouse can store and deliver a product that
     * requires refrigeration.
     *
     * A warehouse needs BOTH cold storage (to keep the product safe while
     * held at the facility) AND refrigerated delivery capability (to maintain
     * the cold chain during transport).  Having only cold storage without
     * refrigerated vans is insufficient.
     */
    public function canHandleRefrigerated(): bool
    {
        return $this->has_cold_storage && $this->has_refrigerated_delivery;
    }
}
