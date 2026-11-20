<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'plate_number',
        'name',
        'type',
        'max_orders',
        'capacity_kg',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'max_orders' => 'integer',
            'capacity_kg' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    // Vehicle can be used in many routes
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }
}