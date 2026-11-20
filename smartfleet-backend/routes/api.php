<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\DriverLocationController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

// public auth endpoint 
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

//protceted endpoints
Route::middleware('auth:sanctum')->group(
    function () {
        // Add your protected routes here
        Route::get('/me',[AuthController::class, 'me']);

        Route::post('/logout', [AuthController::class, 'logout']);
    }

);

// Admin-only fleet management
Route::middleware([
    'auth:sanctum',
    'can:manage-fleet'
])->group(function () {

    Route::get('/drivers', [
        DriverController::class,
        'index'
    ]);

    Route::post('/drivers', [
        DriverController::class,
        'store'
    ]);

    Route::get('/vehicles', [
        VehicleController::class,
        'index'
    ]);

    Route::post('/vehicles', [
        VehicleController::class,
        'store'
    ]);

    Route::get('/orders', [
        OrderController::class,
        'index'
    ]);

    Route::post('/orders', [
        OrderController::class,
        'store'
    ]);

    Route::get('/routes', [
        RouteController::class,
        'index'
    ]);

    Route::post('/routes', [
        RouteController::class,
        'store'
    ]);

    

});

Route::post('/driver/location', [
    DriverLocationController::class,
    'store'
])->middleware(['auth:sanctum', 'throttle:30,1']);
// SMARTFLEET_DRIVER_ROUTE_EXECUTION_V1
\Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('driver/routes')->group(function () {
    \Illuminate\Support\Facades\Route::get('/', [\App\Http\Controllers\Api\DriverRouteExecutionController::class, 'index']);
    \Illuminate\Support\Facades\Route::get('/{routeId}', [\App\Http\Controllers\Api\DriverRouteExecutionController::class, 'show'])->whereNumber('routeId');
    \Illuminate\Support\Facades\Route::post('/{routeId}/start', [\App\Http\Controllers\Api\DriverRouteExecutionController::class, 'start'])->whereNumber('routeId');
    \Illuminate\Support\Facades\Route::post('/{routeId}/stops/{stopId}/deliver', [\App\Http\Controllers\Api\DriverRouteExecutionController::class, 'deliver'])->whereNumber('routeId')->whereNumber('stopId');
    \Illuminate\Support\Facades\Route::post('/{routeId}/finish', [\App\Http\Controllers\Api\DriverRouteExecutionController::class, 'finish'])->whereNumber('routeId');
});
