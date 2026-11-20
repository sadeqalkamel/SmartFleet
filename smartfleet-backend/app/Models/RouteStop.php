<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'route_id',
        'order_id',
        'stop_sequence',
        'estimated_arrival_at',
        'actual_arrival_at',
        'completed_at',
        'distance_from_previous_meters',
        'travel_duration_minutes',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'stop_sequence' => 'integer',
            'estimated_arrival_at' => 'datetime',
            'actual_arrival_at' => 'datetime',
            'completed_at' => 'datetime',
            'distance_from_previous_meters' => 'integer',
            'travel_duration_minutes' => 'integer',
        ];
    }

    // Parent route
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    // Delivery order
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}