<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route as DeliveryRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

class RouteOptimizationWorkbenchController extends Controller
{
    public function index()
    {
        $routes = DeliveryRoute::query()
            ->where('status', 'planned')
            ->whereHas('stops')
            ->withCount('stops')
            ->latest('id')
            ->limit(50)
            ->get(['id', 'route_number', 'route_date', 'vehicle_id', 'driver_id']);

        return view('admin.optimization-workbench', compact('routes'));
    }

    private function getRoute(int $id): DeliveryRoute
    {
        $route = DeliveryRoute::query()
            ->with(['vehicle', 'stops.order'])
            ->findOrFail($id);

        if ($route->status !== 'planned') {
            throw ValidationException::withMessages(['route_id' => 'Only planned routes can be optimized.']);
        }
        if (!$route->vehicle || !$route->vehicle->is_active || $route->vehicle->status !== 'available') {
            throw ValidationException::withMessages(['route_id' => 'Assigned vehicle is not available.']);
        }
        if ($route->vehicle->capacity_kg === null || (float) $route->vehicle->capacity_kg <= 0 || (int) $route->vehicle->max_orders <= 0) {
            throw ValidationException::withMessages(['route_id' => 'Vehicle capacity is invalid.']);
        }
        if ($route->start_latitude === null || $route->start_longitude === null) {
            throw ValidationException::withMessages(['route_id' => 'Route starting coordinates are missing.']);
        }
        if ($route->stops->isEmpty() || $route->stops->count() > 15) {
            throw ValidationException::withMessages(['route_id' => 'The workbench supports 1 to 15 stops per route.']);
        }
        if ($route->stops->contains(fn ($stop) => $stop->status !== 'pending' || !$stop->order)) {
            throw ValidationException::withMessages(['route_id' => 'All stops must be pending and have valid orders.']);
        }
        $ids = $route->stops->pluck('order_id')->map(fn ($id) => (int) $id)->all();
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['route_id' => 'Duplicated orders on route.']);
        }
        return $route;
    }

    private function snapshot(DeliveryRoute $route): string
    {
        $stops = $route->stops->sortBy('stop_sequence')->values();
        $data = [
            'route_id' => $route->id,
            'status' => $route->status,
            'vehicle_id' => $route->vehicle_id,
            'driver_id' => $route->driver_id,
            'route_date' => (string) $route->route_date,
            'start' => [(string) $route->start_latitude, (string) $route->start_longitude],
            'capacity' => [(string) $route->vehicle->capacity_kg, (int) $route->vehicle->max_orders, (string) $route->vehicle->status, (bool) $route->vehicle->is_active],
            'stops' => $stops->map(fn ($s) => [
                (int) $s->id, (int) $s->order_id, (int) $s->stop_sequence, (string) $s->status,
                (string) $s->order->status, (string) $s->order->weight_kg,
                (string) $s->order->latitude, (string) $s->order->longitude,
            ])->all(),
        ];
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function optimize(DeliveryRoute $route): array
    {
        $stops = $route->stops->sortBy('stop_sequence')->values();
        $payload = [
            'depot' => [
                'latitude' => (float) $route->start_latitude,
                'longitude' => (float) $route->start_longitude,
            ],
            'vehicles' => [[
                'id' => (int) $route->vehicle_id,
                'capacity_kg' => (float) $route->vehicle->capacity_kg,
                'max_orders' => (int) $route->vehicle->max_orders,
            ]],
            'orders' => $stops->map(fn ($stop) => [
                'id' => (int) $stop->order_id,
                'latitude' => (float) $stop->order->latitude,
                'longitude' => (float) $stop->order->longitude,
                'weight_kg' => (float) $stop->order->weight_kg,
            ])->all(),
        ];

        $python = base_path('../route-optimizer/.venv/Scripts/python.exe');
        $script = base_path('../route-optimizer/optimizer_road_api.py');
        if (!is_file($python) || !is_file($script)) {
            throw ValidationException::withMessages(['route_id' => 'Python optimizer was not found.']);
        }

        try {
            $process = Process::timeout(45)
                ->env(['SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'windir' => getenv('windir') ?: 'C:\\Windows'])
                ->input(json_encode($payload, JSON_THROW_ON_ERROR))
                ->run([$python, $script]);
            $result = json_decode($process->output(), true, 512, JSON_THROW_ON_ERROR);
            if (!$process->successful() || !($result['success'] ?? false)) {
                Log::warning('SmartFleet optimization unavailable', ['route_id' => $route->id, 'result_message' => $result['message'] ?? 'unknown', 'exit_code' => $process->exitCode()]);
                throw ValidationException::withMessages(['route_id' => 'Optimization unavailable or no feasible route. Check capacities and OSRM connectivity.']);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('SmartFleet optimizer exception', ['route_id' => $route->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['route_id' => 'Could not run optimizer. Check Laravel logs.']);
        }

        $routes = $result['routes'] ?? [];
        if (count($routes) !== 1 || (int) ($routes[0]['vehicle_id'] ?? 0) !== (int) $route->vehicle_id) {
            throw ValidationException::withMessages(['route_id' => 'Optimizer did not return exactly one assigned vehicle.']);
        }
        $optimized = array_map('intval', $routes[0]['order_ids'] ?? []);
        $current = $stops->pluck('order_id')->map(fn ($id) => (int) $id)->all();
        $sortedCurrent = $current;
        $sortedOptimized = $optimized;
        sort($sortedCurrent);
        sort($sortedOptimized);
        if ($sortedCurrent !== $sortedOptimized || count($optimized) !== count(array_unique($optimized))) {
            throw ValidationException::withMessages(['route_id' => 'Optimizer result is inconsistent with current route.']);
        }
        return ['result' => $result, 'original_order_ids' => $current, 'optimized_order_ids' => $optimized];
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['route_id' => ['required', 'integer', 'exists:routes,id']]);
        $route = $this->getRoute((int) $data['route_id']);
        $snapshot = $this->snapshot($route);
        $optimization = $this->optimize($route);
        return response()->json([
            'success' => true,
            'preview_only' => true,
            'route_id' => $route->id,
            'route_number' => $route->route_number,
            'snapshot' => $snapshot,
            ...$optimization,
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_id' => ['required', 'integer', 'exists:routes,id'],
            'snapshot' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'optimized_order_ids' => ['required', 'array', 'min:1', 'max:15'],
            'optimized_order_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        // Compute outside the transaction so external OSRM calls never hold DB locks.
        $route = $this->getRoute((int) $data['route_id']);
        if (!hash_equals($this->snapshot($route), $data['snapshot'])) {
            throw ValidationException::withMessages(['route_id' => 'Route changed since preview; preview again.']);
        }
        $optimized = $this->optimize($route)['optimized_order_ids'];
        if ($optimized !== array_map('intval', $data['optimized_order_ids'])) {
            throw ValidationException::withMessages(['route_id' => 'Preview expired or optimization changed; preview again.']);
        }

        return DB::transaction(function () use ($data, $optimized) {
            $route = DeliveryRoute::query()->whereKey($data['route_id'])->lockForUpdate()->firstOrFail();
            $stops = DB::table('route_stops')->where('route_id', $route->id)->orderBy('id')->lockForUpdate()->get();
            // The refreshed snapshot ensures no concurrent edit occurred while optimizing.
            $fresh = $this->getRoute((int) $route->id);
            if (!hash_equals($this->snapshot($fresh), $data['snapshot'])) {
                throw ValidationException::withMessages(['route_id' => 'Route changed while optimizing; preview again.']);
            }
            $current = $stops->sortBy('stop_sequence')->pluck('order_id')->map(fn ($v) => (int) $v)->values()->all();
            $a = $current;
            $b = $optimized;
            sort($a);
            sort($b);
            if ($a !== $b || $stops->contains(fn ($s) => $s->status !== 'pending')) {
                throw ValidationException::withMessages(['route_id' => 'Route orders or stop statuses have changed.']);
            }
            // Two phases avoid collisions if (route_id, stop_sequence) is unique.
            $offset = max((int) $stops->max('stop_sequence'), count($stops)) + count($stops) + 1;
            foreach ($stops as $index => $stop) {
                DB::table('route_stops')->where('id', $stop->id)->update(['stop_sequence' => $offset + $index, 'updated_at' => now()]);
            }
            $byOrder = $stops->keyBy('order_id');
            foreach ($optimized as $index => $orderId) {
                DB::table('route_stops')->where('id', $byOrder[$orderId]->id)->update(['stop_sequence' => $index + 1, 'updated_at' => now()]);
            }
            return response()->json([
                'success' => true,
                'saved_to_database' => true,
                'route_id' => $route->id,
                'optimized_order_ids' => $optimized,
                'message' => 'Stop order saved. Refresh the map to see the updated sequence.',
            ]);
        }, 3);
    }
}
