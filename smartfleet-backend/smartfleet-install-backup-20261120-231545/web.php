<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Admin\RouteOptimizationController;

Route::get('/', function () {
    return view('welcome');
});

// Web session authentication for broadcasting
Broadcast::routes([
    'middleware' => ['web', 'auth'],
]);

// Admin login page
Route::get('/admin/login', function () {
    if (
        Auth::check() &&
        Auth::user()->role === 'admin'
    ) {
        return redirect()->route('admin.home');
    }

    return view('admin.login');
})->name('login');

// Admin login processing
Route::post('/admin/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    if (!Auth::attempt($credentials)) {
        return back()
            ->withErrors([
                'email' => 'Invalid email or password.',
            ])
            ->onlyInput('email');
    }

    if (Auth::user()->role !== 'admin') {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return back()
            ->withErrors([
                'email' => 'Invalid email or password.',
            ])
            ->onlyInput('email');
    }

    $request->session()->regenerate();

    return redirect()->route('admin.home');
})->middleware('throttle:5,1')
  ->name('admin.login.submit');

// Protected admin page
Route::get('/admin', function () {
    return view('admin.dashboard');
})->middleware([
    'auth',
    'can:manage-fleet',
])->name('admin.home');

// Admin logout
Route::post('/admin/logout', function (Request $request) {
    Auth::logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')
  ->name('admin.logout');


// Latest GPS location for each driver
Route::get('/admin/driver-locations', function () {

    $latestIds = \App\Models\DriverLocation::query()
        ->selectRaw('MAX(id)')
        ->groupBy('driver_id');

    $locations = \App\Models\DriverLocation::query()
        ->with('driver.user')
        ->whereIn('id', $latestIds)
        ->get()
        ->map(function ($location) {

            return [
                'driver_id' => $location->driver_id,

                'driver_name' =>
                    $location->driver?->user?->name
                    ?? 'Driver #' . $location->driver_id,

                'latitude' => (float) $location->latitude,

                'longitude' => (float) $location->longitude,

                'recorded_at' =>
                    $location->recorded_at
                        ?->toIso8601String(),
            ];
        });

    return response()->json($locations);

})->middleware([
    'auth',
    'can:manage-fleet',
])->name('admin.driver-locations');


// Get active delivery routes for admin map
Route::get('/admin/map-routes', function () {

    $routes = \App\Models\Route::query()
        ->with([
            'driver.user',
            'vehicle',
            'stops.order',
        ])
        ->whereIn('status', ['planned', 'in_progress'])
        ->latest('id')
        ->limit(25)
        ->get()
        ->map(function ($route) {

            return [
                'id' => $route->id,
                'route_number' => $route->route_number,
                'status' => $route->status,

                'driver_name' =>
                    $route->driver?->user?->name,

                'vehicle_name' =>
                    $route->vehicle?->name,

                'start' => [
                    'latitude' =>
                        (float) $route->start_latitude,

                    'longitude' =>
                        (float) $route->start_longitude,
                ],

                'stops' => $route->stops->map(
                    function ($stop) {

                        return [
                            'sequence' =>
                                $stop->stop_sequence,

                            'order_number' =>
                                $stop->order?->order_number,

                            'latitude' =>
                                (float) $stop->order->latitude,

                            'longitude' =>
                                (float) $stop->order->longitude,

                            'status' => $stop->status,
                        ];
                    }
                )->values(),
            ];
        });

    return response()->json($routes);

})->middleware([
    'auth',
    'can:manage-fleet',
])->name('admin.map-routes');


// Preview route optimization (Admin only)
Route::get(
    '/admin/optimization-preview',
    [RouteOptimizationController::class, 'preview']
)->middleware([
    'auth',
    'can:manage-fleet',
    'throttle:6,1',
])->name('admin.optimization.preview');