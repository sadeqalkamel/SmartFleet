<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route as DeliveryRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use App\Models\Vehicle;

class RouteOptimizationController extends Controller
{
    public function preview(): JsonResponse
    {
        // Load latest planned route and its orders
        $route = DeliveryRoute::query()
            ->with([
                'vehicle:id,capacity_kg,max_orders',
                'stops.order:id,latitude,longitude,weight_kg',
            ])
            ->where('status', 'planned')
            ->whereHas('stops')
            ->latest('id')
            ->first();

        if (!$route) {
            return response()->json([
                'message' => 'No planned route found.',
            ], 404);
        }

        if ($route->stops->count() > 15) {
            return response()->json([
                'message' => 'Demo supports up to 15 orders.',
            ], 422);
        }

        if (
            !$route->vehicle ||
            $route->vehicle->capacity_kg === null ||
            $route->stops->contains(
                fn ($stop) => $stop->order === null
            )
        ) {
            return response()->json([
                'message' => 'Incomplete route data.',
            ], 422);
        }

        $orders = $route->stops->map(
            fn ($stop) => [
                'id' => $stop->order->id,
                'latitude' => (float) $stop->order->latitude,
                'longitude' => (float) $stop->order->longitude,
                'weight_kg' => (float) $stop->order->weight_kg,
            ]
        )->values()->all();

        $vehicles = Vehicle::query()
    ->where('capacity_kg', '>', 0)
    ->where('max_orders', '>', 0)
    ->orderBy('id')
    ->get(['id', 'capacity_kg', 'max_orders']);

if ($vehicles->isEmpty()) {
    return response()->json([
        'message' => 'No vehicles with valid capacity found.',
    ], 422);
}

        // Prepare JSON for Python optimizer
        $payload = [
            'depot' => [
                'latitude' => (float) $route->start_latitude,
                'longitude' => (float) $route->start_longitude,
            ],

'vehicles' => $vehicles->map(
    fn ($vehicle) => [
        'id' => $vehicle->id,
        'capacity_kg' => (float) $vehicle->capacity_kg,
        'max_orders' => (int) $vehicle->max_orders,
    ]
)->values()->all(),

            'orders' => $orders,
        ];

        $python = base_path(
            '../route-optimizer/.venv/Scripts/python.exe'
        );

        $script = base_path(
            '../route-optimizer/optimizer_road_api.py'
        );

        if (!is_file($python) || !is_file($script)) {
            return response()->json([
                'message' => 'Python optimizer files not found.',
            ], 503);
        }

        try {
            // Execute Python with JSON through stdin
            $process = Process::timeout(40)
    ->env([
        'SystemRoot' => 'C:\\Windows',
        'windir' => 'C:\\Windows',
    ])
    ->input(json_encode(
        $payload,
        JSON_THROW_ON_ERROR
    ))
    ->run([$python, $script]);

           if (!$process->successful()) {

    $networkCheck = Process::timeout(15)->run([
        $python,
        '-c',
        'import socket, urllib.request; print("PROXY_TYPES:", ",".join(urllib.request.getproxies().keys())); print("DNS:", socket.gethostbyname("router.project-osrm.org"))'
    ]);

    Log::warning('SmartFleet optimizer failed', [
        'route_id' => $route->id,
        'python_output' => $process->output(),
        'network_check' => $networkCheck->output(),
        'network_error' => $networkCheck->errorOutput(),
    ]);

    return response()->json([
        'message' => 'Python network diagnostic',
        'python_output' => trim($process->output()),
        'network_check' => trim($networkCheck->output()),
        'network_error' => trim($networkCheck->errorOutput()),
        'network_exit_code' => $networkCheck->exitCode(),
    ], 502);
}

            $result = json_decode(
                $process->output(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (
                !($result['success'] ?? false) ||
                !isset($result['routes']) ||
                !is_array($result['routes'])
            ) {
                return response()->json([
                    'message' => 'No feasible route found.',
                ], 422);
            }

            // Verify all orders were assigned exactly once
            $expected = array_column($orders, 'id');

            $assigned = [];

            foreach ($result['routes'] as $optimizedRoute) {
                foreach ($optimizedRoute['order_ids'] ?? [] as $id) {
                    $assigned[] = $id;
                }
            }

            sort($expected);
            sort($assigned);

            if ($expected !== $assigned) {
                throw new \RuntimeException(
                    'Optimizer order assignment mismatch.'
                );
            }

            return response()->json([
                'success' => true,
                'mode' => 'preview_only',
                'saved_to_database' => false,
                'route_id' => $route->id,
                'route_number' => $route->route_number,
                'original_order_ids' => array_column(
                    $orders,
                    'id'
                ),
                'optimization' => $result,
            ]);

        } catch (\Throwable $exception) {
            Log::error('SmartFleet optimization error', [
                'route_id' => $route->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Optimizer execution error.',
            ], 502);
        }
    }
}