<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Events\DriverLocationUpdated;

class DriverLocationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Get authenticated user
        $user = $request->user();

        // Only drivers can send GPS updates
        if ($user->role !== 'driver') {
            return response()->json([
                'message' => 'Only drivers can send locations.'
            ], 403);
        }

        // Get driver profile
        $driver = $user->driver;

        if (!$driver || !$driver->is_active) {
            return response()->json([
                'message' => 'Driver account inactive.'
            ], 403);
        }

        // Validate GPS data
        $data = $request->validate([
            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'accuracy_meters' => [
                'nullable',
                'numeric',
                'between:0,10000',
            ],

            'speed_mps' => [
                'nullable',
                'numeric',
                'between:0,120',
            ],

            'heading_degrees' => [
                'nullable',
                'numeric',
                'between:0,359.99',
            ],

            'route_id' => [
                'nullable',
                'integer',
                Rule::exists('routes', 'id')
                    ->where('driver_id', $driver->id)
                    ->whereIn(
                        'status',
                        ['planned', 'in_progress']
                    ),
            ],
        ]);

        // Driver identity is taken from login token
        // Never trust a driver_id sent by the phone
        $location = $driver->locations()->create([
            'route_id' => $data['route_id'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'accuracy_meters' =>
                $data['accuracy_meters'] ?? null,
            'speed_mps' =>
                $data['speed_mps'] ?? null,
            'heading_degrees' =>
                $data['heading_degrees'] ?? null,
            'recorded_at' => now(),
            'received_at' => now(),
        ]);
        event(new DriverLocationUpdated($location));

        return response()->json([
            'message' => 'Location saved successfully.',
            'location' => $location,
        ], 201);
    }
}