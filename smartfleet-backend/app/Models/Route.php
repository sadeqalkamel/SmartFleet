<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    use HasFactory;

    protected $fillable = [
        'route_number',
        'driver_id',
        'vehicle_id',
        'route_date',
        'status',
        'start_latitude',
        'start_longitude',
        'start_address',
        'total_distance_meters',
        'estimated_duration_minutes',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'route_date' => 'date',
            'start_latitude' => 'decimal:7',
            'start_longitude' => 'decimal:7',
            'total_distance_meters' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // Assigned driver
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    // Assigned vehicle
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    // Ordered delivery stops
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)
            ->orderBy('stop_sequence');
    }

    // GPS records associated with this route
    public function locations(): HasMany
    {
        return $this->hasMany(DriverLocation::class);
    }
}