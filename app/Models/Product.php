<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'requires_refrigeration',
    ];

    protected $casts = [
        'requires_refrigeration' => 'boolean',
    ];

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }
}
