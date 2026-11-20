<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Route as DeliveryRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverRouteExecutionController extends Controller
{
    private function driver(Request $request): Driver
    {
        $driver = Driver::query()->where('user_id', $request->user()->id)->first();
        if (!$driver || !$driver->is_active) {
            abort(403, 'Active driver account required.');
        }
        return $driver;
    }

    private function assignedRoute(int $routeId, int $driverId, bool $lock = false): DeliveryRoute
    {
        $query = DeliveryRoute::query()
            ->whereKey($routeId)
            ->where('driver_id', $driverId);
        if ($lock) $query->lockForUpdate();
        return $query->firstOrFail();
    }

    private function serializeRoute(DeliveryRoute $route): array
    {
        $route->load([
            'vehicle',
            'stops' => fn ($q) => $q->orderBy('stop_sequence'),
            'stops.order',
        ]);
        return [
            'id' => $route->id,
            'route_number' => $route->route_number,
            'route_date' => $route->route_date,
            'status' => $route->status,
            'vehicle' => $route->vehicle,
            'start' => [
                'latitude' => $route->start_latitude,
                'longitude' => $route->start_longitude,
                'address' => $route->start_address,
            ],
            'stops' => $route->stops->map(fn ($stop) => [
                'id' => $stop->id,
                'sequence' => $stop->stop_sequence,
                'status' => $stop->status,
                'order' => $stop->order,
            ])->values(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $routes = DeliveryRoute::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', ['planned', 'in_progress', 'completed'])
            ->orderByDesc('route_date')->orderByDesc('id')
            ->limit(30)->get();
        return response()->json([
            'success' => true,
            'driver_id' => $driver->id,
            'routes' => $routes->map(fn ($r) => [
                'id' => $r->id,
                'route_number' => $r->route_number,
                'route_date' => $r->route_date,
                'status' => $r->status,
            ])->values(),
        ]);
    }

    public function show(Request $request, int $routeId): JsonResponse
    {
        $driver = $this->driver($request);
        $route = $this->assignedRoute($routeId, $driver->id);
        return response()->json(['success' => true, 'route' => $this->serializeRoute($route)]);
    }

    public function start(Request $request, int $routeId): JsonResponse
    {
        $driver = $this->driver($request);
        $route = DB::transaction(function () use ($routeId, $driver) {
            $route = $this->assignedRoute($routeId, $driver->id, true);
            if ($route->status !== 'planned') {
                throw ValidationException::withMessages(['route' => 'Only planned routes can be started.']);
            }
            // The driver must not have another active journey.
            $busy = DeliveryRoute::query()
                ->where('driver_id', $driver->id)
                ->where('id', '<>', $route->id)
                ->where('status', 'in_progress')->exists();
            if ($busy) {
                throw ValidationException::withMessages(['route' => 'Driver already has a route in progress.']);
            }
            $changes = ['status' => 'in_progress'];
            if (\Illuminate\Support\Facades\Schema::hasColumn('routes', 'started_at')) {
                $changes['started_at'] = now();
            }
            DB::table('routes')->where('id', $route->id)->update($changes);
            return $route->fresh();
        }, 3);
        return response()->json(['success' => true, 'message' => 'Route started.', 'route' => $this->serializeRoute($route)]);
    }

    public function deliver(Request $request, int $routeId, int $stopId): JsonResponse
    {
        $driver = $this->driver($request);
        $route = DB::transaction(function () use ($routeId, $stopId, $driver) {
            $route = $this->assignedRoute($routeId, $driver->id, true);
            if ($route->status !== 'in_progress') {
                throw ValidationException::withMessages(['route' => 'Route must be in progress.']);
            }
            $stop = DB::table('route_stops')->where('route_id', $route->id)
                ->where('id', $stopId)->lockForUpdate()->first();
            if (!$stop) abort(404, 'Delivery stop not found.');
            if ($stop->status === 'completed') {
                throw ValidationException::withMessages(['stop' => 'Stop has already been delivered.']);
            }
            if ($stop->status !== 'pending') {
                throw ValidationException::withMessages(['stop' => 'Stop is not pending.']);
            }
            // Enforce visit order; no skipping previous undelivered stops.
            $earlierPending = DB::table('route_stops')
                ->where('route_id', $route->id)
                ->where('stop_sequence', '<', $stop->stop_sequence)
                ->where('status', '<>', 'completed')->exists();
            if ($earlierPending) {
                throw ValidationException::withMessages(['stop' => 'Deliver previous stops first.']);
            }
            $order = Order::query()->whereKey($stop->order_id)->lockForUpdate()->firstOrFail();
            if (!in_array($order->status, ['pending', 'assigned', 'out_for_delivery'], true)) {
                throw ValidationException::withMessages(['order' => 'Order cannot be marked delivered from its current status.']);
            }
            $stopChanges = ['status' => 'completed', 'updated_at' => now()];
            if (\Illuminate\Support\Facades\Schema::hasColumn('route_stops', 'completed_at')) {
                $stopChanges['completed_at'] = now();
            }
            DB::table('route_stops')->where('id', $stop->id)->update($stopChanges);
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'delivered',
                'updated_at' => now(),
            ]);
            return $route->fresh();
        }, 3);
        return response()->json(['success' => true, 'message' => 'Order delivered.', 'route' => $this->serializeRoute($route)]);
    }

    public function finish(Request $request, int $routeId): JsonResponse
    {
        $driver = $this->driver($request);
        $route = DB::transaction(function () use ($routeId, $driver) {
            $route = $this->assignedRoute($routeId, $driver->id, true);
            if ($route->status !== 'in_progress') {
                throw ValidationException::withMessages(['route' => 'Route is not in progress.']);
            }
            $stops = DB::table('route_stops')->where('route_id', $route->id)
                ->lockForUpdate()->get();
            if ($stops->isEmpty() || $stops->contains(fn ($s) => $s->status !== 'completed')) {
                throw ValidationException::withMessages(['route' => 'Complete all delivery stops first.']);
            }
            $changes = ['status' => 'completed'];
            if (\Illuminate\Support\Facades\Schema::hasColumn('routes', 'completed_at')) {
                $changes['completed_at'] = now();
            }
            DB::table('routes')->where('id', $route->id)->update($changes);
            return $route->fresh();
        }, 3);
        return response()->json(['success' => true, 'message' => 'Route completed.', 'route' => $this->serializeRoute($route)]);
    }
}
