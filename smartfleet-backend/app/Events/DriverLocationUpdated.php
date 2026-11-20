<?php

namespace App\Events;

use App\Models\DriverLocation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DriverLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        private DriverLocation $location
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('fleet.admin'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'driver.location.updated';
    }

    public function broadcastWith(): array
    {
        $this->location->loadMissing('driver.user');

        return [
            'driver_id' => $this->location->driver_id,

            'driver_name' =>
                $this->location->driver?->user?->name
                ?? 'Driver #' . $this->location->driver_id,

            'route_id' => $this->location->route_id,

            'latitude' => (float) $this->location->latitude,

            'longitude' => (float) $this->location->longitude,

            'accuracy_meters' =>
                $this->location->accuracy_meters === null
                    ? null
                    : (float) $this->location->accuracy_meters,

            'recorded_at' =>
                $this->location->recorded_at
                    ?->toIso8601String(),
        ];
    }
}