<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverController extends Controller
{
    // List all drivers
    public function index(): JsonResponse
    {
        $drivers = Driver::query()
            ->with('user:id,name,email')
            ->latest()
            ->paginate(15);

        return response()->json($drivers);
    }

    // Create a driver and login account
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255'
            ],
            'email' => [
                'required', 'email',
                'max:255', 'unique:users,email'
            ],
            'password' => [
                'required', 'string',
                'min:12', 'confirmed'
            ],
            'phone' => [
                'nullable', 'string', 'max:30',
                'unique:drivers,phone'
            ],
            'license_number' => [
                'nullable', 'string', 'max:60',
                'unique:drivers,license_number'
            ],
        ]);

        $driver = DB::transaction(function () use ($data) {
            // User model hashes passwords automatically
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            // Role defaults to driver in the database
            $driver = $user->driver()->create([
                'phone' => $data['phone'] ?? null,
                'license_number' =>
                    $data['license_number'] ?? null,
                'status' => 'offline',
                'is_active' => true,
            ]);

            return $driver->load('user:id,name,email');
        });

        return response()->json([
            'message' => 'Driver created successfully.',
            'driver' => $driver,
        ], 201);
    }
}