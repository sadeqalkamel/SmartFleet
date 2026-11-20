<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\Order;
use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RouteController extends Controller
{
    // List all routes
    public function index(): JsonResponse
    {
        $routes = Route::with([
            'driver.user:id,name,email',
            'vehicle',
            'stops.order',
        ])
            ->latest('id')
            ->paginate(15);

        return response()->json($routes);
    }

    // Create a route and assign orders
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'driver_id' => [
                'required', 'integer',
                'exists:drivers,id',
            ],
            'vehicle_id' => [
                'required', 'integer',
                'exists:vehicles,id',
            ],
            'route_date' => [
                'required', 'date_format:Y-m-d',
            ],
            'start_latitude' => [
                'required', 'numeric',
                'between:-90,90',
            ],
            'start_longitude' => [
                'required', 'numeric',
                'between:-180,180',
            ],
            'start_address' => [
                'nullable', 'string', 'max:1000',
            ],
            'order_ids' => [
                'required', 'array', 'min:1', 'max:200',
            ],
            'order_ids.*' => [
                'required', 'integer',
                'distinct', 'exists:orders,id',
            ],
        ]);

        $route = DB::transaction(function () use ($data) {

            // Lock driver and vehicle during assignment
            $driver = Driver::whereKey($data['driver_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $vehicle = Vehicle::whereKey($data['vehicle_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (!$driver->is_active) {
                throw ValidationException::withMessages([
                    'driver_id' => 'Driver is inactive.',
                ]);
            }

            if (
                !$vehicle->is_active ||
                $vehicle->status !== 'available'
            ) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Vehicle is unavailable.',
                ]);
            }

            // Prevent conflicting active assignments
            $conflict = Route::query()
                ->whereDate('route_date', $data['route_date'])
                ->whereIn('status', ['planned', 'in_progress'])
                ->where(function ($query) use ($data) {
                    $query
                        ->where('driver_id', $data['driver_id'])
                        ->orWhere('vehicle_id', $data['vehicle_id']);
                })
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'driver_id' =>
                        'Driver or vehicle already has an active route on this date.',
                ]);
            }

            // Lock orders in consistent ID order
            $orders = Order::query()
                ->whereIn('id', $data['order_ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($data['order_ids'] as $orderId) {
                if ($orders[$orderId]->status !== 'pending') {
                    throw ValidationException::withMessages([
                        'order_ids' => 'Only pending orders can be assigned.',
                    ]);
                }
            }

            // Prevent assigning orders to multiple active routes
            $alreadyAssigned = DB::table('route_stops')
                ->join(
                    'routes',
                    'routes.id',
                    '=',
                    'route_stops.route_id'
                )
                ->whereIn('route_stops.order_id', $data['order_ids'])
                ->whereIn('routes.status', ['planned', 'in_progress'])
                ->exists();

            if ($alreadyAssigned) {
                throw ValidationException::withMessages([
                    'order_ids' => 'An order is already assigned.',
                ]);
            }

            // Check maximum number of orders
            if (count($data['order_ids']) > $vehicle->max_orders) {
                throw ValidationException::withMessages([
                    'order_ids' => 'Vehicle order limit exceeded.',
                ]);
            }

            // Check vehicle weight capacity
            if ($vehicle->capacity_kg === null) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Vehicle weight capacity is required.',
                ]);
            }

            $totalWeight = $orders->sum(
                fn ($order) => (int) round(
                    (float) $order->weight_kg * 100
                )
            );

            $maxWeight = (int) round(
                (float) $vehicle->capacity_kg * 100
            );

            if ($totalWeight > $maxWeight) {
                throw ValidationException::withMessages([
                    'order_ids' => 'Vehicle weight capacity exceeded.',
                ]);
            }

            // Create the route
            $route = Route::create([
                'route_number' => 'RT-' . Str::ulid(),
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'route_date' => $data['route_date'],
                'start_latitude' => $data['start_latitude'],
                'start_longitude' => $data['start_longitude'],
                'start_address' => $data['start_address'] ?? null,
                'status' => 'planned',
            ]);

            // Create ordered delivery stops
            foreach ($data['order_ids'] as $index => $orderId) {
                $route->stops()->create([
                    'order_id' => $orderId,
                    'stop_sequence' => $index + 1,
                    'status' => 'pending',
                ]);
            }

            return $route->load([
                'driver.user:id,name,email',
                'vehicle',
                'stops.order',
            ]);

        }, 3);

        return response()->json([
            'message' => 'Route created successfully.',
            'route' => $route,
        ], 201);
    }
}