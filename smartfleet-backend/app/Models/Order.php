<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'customer_name',
        'customer_phone',
        'delivery_address',
        'latitude',
        'longitude',
        'weight_kg',
        'service_minutes',
        'delivery_window_start',
        'delivery_window_end',
        'priority',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'weight_kg' => 'decimal:2',
            'service_minutes' => 'integer',
            'delivery_window_start' => 'datetime',
            'delivery_window_end' => 'datetime',
        ];
    }

    // Delivery history / assigned route stops
    public function routeStops(): HasMany
    {
        return $this->hasMany(RouteStop::class);
    }
}