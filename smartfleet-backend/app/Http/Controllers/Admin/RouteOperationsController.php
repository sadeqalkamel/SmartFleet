<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route as DeliveryRoute;
use Illuminate\Http\JsonResponse;

class RouteOperationsController extends Controller
{
    public function index()
    {
        return view('admin.route-operations');
    }

    public function data(): JsonResponse
    {
        $routes = DeliveryRoute::query()
            ->with(['driver.user:id,name', 'vehicle', 'stops.order'])
            ->whereIn('status', ['planned', 'in_progress', 'completed'])
            ->orderByDesc('id')->limit(60)->get();

        return response()->json([
            'success' => true,
            'routes' => $routes->map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->route_number,
                'date' => $r->route_date,
                'status' => $r->status,
                'driver' => $r->driver?->user?->name ?? ('#'.$r->driver_id),
                'vehicle_id' => $r->vehicle_id,
                'total_stops' => $r->stops->count(),
                'delivered_stops' => $r->stops->where('status', 'completed')->count(),
                'stops' => $r->stops->sortBy('stop_sequence')->map(fn ($s) => [
                    'sequence' => $s->stop_sequence,
                    'order_number' => $s->order?->order_number ?? ('#'.$s->order_id),
                    'status' => $s->status,
                ])->values(),
            ])->values(),
        ]);
    }
}
