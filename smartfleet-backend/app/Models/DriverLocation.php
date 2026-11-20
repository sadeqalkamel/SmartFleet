<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'route_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'speed_mps',
        'heading_degrees',
        'recorded_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'decimal:2',
            'speed_mps' => 'decimal:2',
            'heading_degrees' => 'decimal:2',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    // Location belongs to one driver
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    // Location may belong to a route
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}