<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Route as DeliveryRoute;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MultiVehicleDispatchController extends Controller
{
    public function index()
    {
        return view('admin.multi-vehicle-dispatch');
    }

    private function eligibleOrders(): array
    {
        return Order::query()
            ->where('status', 'pending')
            ->whereNotIn('id', function ($query) {
                $query->select('route_stops.order_id')
                    ->from('route_stops')
                    ->join('routes', 'routes.id', '=', 'route_stops.route_id')
                    ->whereIn('routes.status', ['planned', 'in_progress']);
            })
            ->orderBy('id')->limit(16)->get()
            ->map(fn ($o) => [
                'id' => (int) $o->id,
                'latitude' => (float) $o->latitude,
                'longitude' => (float) $o->longitude,
                'weight_kg' => (float) $o->weight_kg,
            ])->all();
    }

    private function pairs(string $date): array
    {
        $busyDrivers = DeliveryRoute::query()->whereDate('route_date', $date)
            ->whereIn('status', ['planned', 'in_progress'])->pluck('driver_id')->all();
        $busyVehicles = DeliveryRoute::query()->whereDate('route_date', $date)
            ->whereIn('status', ['planned', 'in_progress'])->pluck('vehicle_id')->all();

        $drivers = Driver::query()->where('is_active', true)
            ->whereNotIn('id', $busyDrivers)->orderBy('id')->limit(15)->get();
        $vehicles = Vehicle::query()->where('is_active', true)
            ->where('status', 'available')->where('capacity_kg', '>', 0)
            ->where('max_orders', '>', 0)->whereNotIn('id', $busyVehicles)
            ->orderBy('id')->limit(15)->get();

        $pairs = [];
        $count = min($drivers->count(), $vehicles->count());
        for ($i = 0; $i < $count; $i++) {
            $pairs[] = [
                'driver_id' => (int) $drivers[$i]->id,
                'vehicle_id' => (int) $vehicles[$i]->id,
                'capacity_kg' => (float) $vehicles[$i]->capacity_kg,
                'max_orders' => (int) $vehicles[$i]->max_orders,
            ];
        }
        return $pairs;
    }

    private function snapshot(array $orders, array $pairs, array $input): string
    {
        return hash('sha256', json_encode([$orders, $pairs, $input], JSON_THROW_ON_ERROR));
    }

    private function input(Request $request): array
    {
        $data = $request->validate([
            'route_date' => ['required', 'date_format:Y-m-d'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        return [
            'route_date' => $data['route_date'],
            'latitude' => round((float) $data['latitude'], 7),
            'longitude' => round((float) $data['longitude'], 7),
        ];
    }

    public function preview(Request $request): JsonResponse
    {
        $input = $this->input($request);
        $orders = $this->eligibleOrders();
        $pairs = $this->pairs($input['route_date']);

        if (!$orders) {
            throw ValidationException::withMessages(['orders' => 'No unassigned pending orders.']);
        }
        if (count($orders) > 15) {
            throw ValidationException::withMessages(['orders' => 'Demo supports at most 15 orders; narrow your pending order set.']);
        }
        if (!$pairs) {
            throw ValidationException::withMessages(['vehicles' => 'No available driver/vehicle pairs for this date.']);
        }
        $python = base_path('../route-optimizer/.venv/Scripts/python.exe');
        $script = base_path('../route-optimizer/optimizer_road_api.py');
        if (!is_file($python) || !is_file($script)) {
            throw ValidationException::withMessages(['optimizer' => 'Python optimizer not found.']);
        }
        $payload = [
            'depot' => ['latitude' => $input['latitude'], 'longitude' => $input['longitude']],
            'orders' => $orders,
            'vehicles' => array_map(fn ($p) => [
                'id' => $p['vehicle_id'],
                'capacity_kg' => $p['capacity_kg'],
                'max_orders' => $p['max_orders'],
            ], $pairs),
        ];
        try {
            $process = Process::timeout(45)->env([
                'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
                'windir' => getenv('windir') ?: 'C:\\Windows',
            ])->input(json_encode($payload, JSON_THROW_ON_ERROR))
                ->run([$python, $script]);
            $result = json_decode($process->output(), true, 512, JSON_THROW_ON_ERROR);
            if (!$process->successful() || !($result['success'] ?? false)) {
                Log::warning('SmartFleet multi-dispatch optimization failed', [
                    'exit_code' => $process->exitCode(),
                    'message' => $result['message'] ?? 'No feasible result',
                ]);
                throw ValidationException::withMessages(['optimizer' => 'Optimization failed or no feasible assignment. Check capacities, orders, and OSRM.']);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('SmartFleet multi-dispatch error', ['message' => $e->getMessage()]);
            throw ValidationException::withMessages(['optimizer' => 'Optimization unavailable. Check Laravel logs.']);
        }

        $pairMap = collect($pairs)->keyBy('vehicle_id');
        $ordersById = collect($orders)->keyBy('id');
        $assigned = [];
        $dispatch = [];
        foreach (($result['routes'] ?? []) as $route) {
            $vehicleId = (int) ($route['vehicle_id'] ?? 0);
            $pair = $pairMap->get($vehicleId);
            if (!$pair) throw ValidationException::withMessages(['optimizer' => 'Unexpected vehicle in result.']);
            $ids = array_map('intval', $route['order_ids'] ?? []);
            if (count($ids) > $pair['max_orders']) throw ValidationException::withMessages(['optimizer' => 'Order capacity exceeded.']);
            $grams = 0;
            foreach ($ids as $id) {
                if (!$ordersById->has($id)) throw ValidationException::withMessages(['optimizer' => 'Unexpected order in result.']);
                $assigned[] = $id;
                $grams += (int) round((float) $ordersById[$id]['weight_kg'] * 1000);
            }
            if ($grams > (int) round($pair['capacity_kg'] * 1000)) {
                throw ValidationException::withMessages(['optimizer' => 'Weight capacity exceeded.']);
            }
            if ($ids) $dispatch[] = [
                'vehicle_id' => $vehicleId,
                'driver_id' => $pair['driver_id'],
                'order_ids' => $ids,
                'distance_meters' => (int) ($route['distance_meters'] ?? 0),
                'duration_seconds' => (int) ($route['duration_seconds'] ?? 0),
                'load_kg' => $grams / 1000,
            ];
        }
        $expected = array_column($orders, 'id');
        sort($expected);
        sort($assigned);
        if ($assigned !== $expected || count($dispatch) === 0) {
            throw ValidationException::withMessages(['optimizer' => 'Order assignment incomplete or duplicated.']);
        }

        $token = Str::random(48);
        $snapshot = $this->snapshot($orders, $pairs, $input);
        $request->session()->put('sf_dispatch_preview', [
            'token_hash' => hash('sha256', $token),
            'created_at' => time(),
            'input' => $input,
            'snapshot' => $snapshot,
            'dispatch' => $dispatch,
            'order_ids' => $expected,
        ]);
        return response()->json([
            'success' => true,
            'preview_only' => true,
            'token' => $token,
            'orders_count' => count($orders),
            'total_distance_meters' => (int) $result['total_distance_meters'],
            'total_duration_seconds' => (int) $result['total_duration_seconds'],
            'dispatch' => $dispatch,
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'size:48']]);
        $preview = $request->session()->get('sf_dispatch_preview');
        if (!$preview || time() - ($preview['created_at'] ?? 0) > 600 ||
            !hash_equals($preview['token_hash'], hash('sha256', $request->string('token')->toString()))) {
            throw ValidationException::withMessages(['token' => 'Preview expired. Calculate again.']);
        }

        $created = DB::transaction(function () use ($preview) {
            $input = $preview['input'];
            $dispatch = $preview['dispatch'];
            $driverIds = array_column($dispatch, 'driver_id');
            $vehicleIds = array_column($dispatch, 'vehicle_id');
            $orderIds = $preview['order_ids'];

            // Match the lock order used by RouteController: drivers, vehicles, orders.
            $drivers = Driver::query()->whereIn('id', $driverIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $vehicles = Vehicle::query()->whereIn('id', $vehicleIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $orders = Order::query()->whereIn('id', $orderIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $busy = DeliveryRoute::query()->whereDate('route_date', $input['route_date'])
                ->whereIn('status', ['planned', 'in_progress'])
                ->where(function ($q) use ($driverIds, $vehicleIds) {
                    $q->whereIn('driver_id', $driverIds)->orWhereIn('vehicle_id', $vehicleIds);
                })->exists();
            $assigned = DB::table('route_stops')->join('routes', 'routes.id', '=', 'route_stops.route_id')
                ->whereIn('route_stops.order_id', $orderIds)
                ->whereIn('routes.status', ['planned', 'in_progress'])->exists();
            if ($busy || $assigned) throw ValidationException::withMessages(['dispatch' => 'Assignments changed. Preview again.']);

            foreach ($dispatch as $item) {
                $driver = $drivers->get($item['driver_id']);
                $vehicle = $vehicles->get($item['vehicle_id']);
                if (!$driver || !$driver->is_active || !$vehicle || !$vehicle->is_active ||
                    $vehicle->status !== 'available' || $vehicle->capacity_kg === null ||
                    count($item['order_ids']) > (int) $vehicle->max_orders) {
                    throw ValidationException::withMessages(['dispatch' => 'A driver or vehicle is unavailable. Preview again.']);
                }
                $weight = 0;
                foreach ($item['order_ids'] as $id) {
                    $order = $orders->get($id);
                    if (!$order || $order->status !== 'pending') {
                        throw ValidationException::withMessages(['dispatch' => 'Order is no longer pending. Preview again.']);
                    }
                    $weight += (int) round((float) $order->weight_kg * 1000);
                }
                if ($weight > (int) round((float) $vehicle->capacity_kg * 1000)) {
                    throw ValidationException::withMessages(['dispatch' => 'Vehicle capacity exceeded. Preview again.']);
                }
            }
            $freshOrders = $this->eligibleOrders();
            $freshPairs = $this->pairs($input['route_date']);
            if (!hash_equals($preview['snapshot'], $this->snapshot($freshOrders, $freshPairs, $input))) {
                throw ValidationException::withMessages(['dispatch' => 'Fleet or orders changed. Preview again.']);
            }

            $routes = [];
            foreach ($dispatch as $item) {
                $route = DeliveryRoute::create([
                    'route_number' => 'RT-' . Str::ulid(),
                    'driver_id' => $item['driver_id'],
                    'vehicle_id' => $item['vehicle_id'],
                    'route_date' => $input['route_date'],
                    'start_latitude' => $input['latitude'],
                    'start_longitude' => $input['longitude'],
                    'status' => 'planned',
                ]);
                foreach ($item['order_ids'] as $index => $orderId) {
                    $route->stops()->create([
                        'order_id' => $orderId,
                        'stop_sequence' => $index + 1,
                        'status' => 'pending',
                    ]);
                }
                $routes[] = ['route_id' => $route->id, 'route_number' => $route->route_number, 'vehicle_id' => $item['vehicle_id'], 'driver_id' => $item['driver_id'], 'order_ids' => $item['order_ids']];
            }
            return $routes;
        }, 3);
        $request->session()->forget('sf_dispatch_preview');
        return response()->json(['success' => true, 'saved_to_database' => true, 'routes' => $created]);
    }
}
