<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'license_number',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // Driver belongs to one user
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Driver can have multiple routes
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    // Driver GPS location history
    public function locations(): HasMany
    {
        return $this->hasMany(DriverLocation::class);
    }
}