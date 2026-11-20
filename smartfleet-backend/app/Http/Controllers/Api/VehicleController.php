<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    // List all vehicles
    public function index(): JsonResponse
    {
        $vehicles = Vehicle::query()
            ->latest()
            ->paginate(15);

        return response()->json($vehicles);
    }

    // Create a new vehicle
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plate_number' => [
                'required',
                'string',
                'max:30',
                'unique:vehicles,plate_number',
            ],

            'name' => [
                'required',
                'string',
                'max:100',
            ],

            'type' => [
                'required',
                Rule::in([
                    'car',
                    'motorcycle',
                    'van',
                    'truck',
                ]),
            ],

            'max_orders' => [
                'required',
                'integer',
                'between:1,200',
            ],

            'capacity_kg' => [
                'nullable',
                'numeric',
                'between:0.01,999999.99',
            ],
        ]);

        $vehicle = Vehicle::create([
            ...$data,
            'status' => 'available',
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Vehicle created successfully.',
            'vehicle' => $vehicle,
        ], 201);
    }
}