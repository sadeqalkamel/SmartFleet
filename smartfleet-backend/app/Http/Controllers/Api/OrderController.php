<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    // List delivery orders
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => [
                'sometimes',
                'string',
                'max:30',
            ],
            'priority' => [
                'sometimes',
                Rule::in(['normal', 'high', 'urgent']),
            ],
        ]);

        $orders = Order::query()
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where(
                    'status',
                    $filters['status']
                )
            )
            ->when(
                isset($filters['priority']),
                fn ($query) => $query->where(
                    'priority',
                    $filters['priority']
                )
            )
            ->latest('id')
            ->paginate(15);

        return response()->json($orders);
    }

    // Create delivery order
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => [
                'required',
                'string',
                'max:150',
            ],

            'customer_phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'delivery_address' => [
                'required',
                'string',
                'max:1000',
            ],

            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'weight_kg' => [
                'required',
                'numeric',
                'between:0.01,99999999.99',
            ],

            'service_minutes' => [
                'sometimes',
                'integer',
                'between:1,1440',
            ],

            'delivery_window_start' => [
                'nullable',
                'required_with:delivery_window_end',
                'date',
            ],

            'delivery_window_end' => [
                'nullable',
                'required_with:delivery_window_start',
                'date',
                'after:delivery_window_start',
            ],

            'priority' => [
                'sometimes',
                Rule::in([
                    'normal',
                    'high',
                    'urgent',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        // Generate unique order number
        $data['order_number'] = 'SF-' . Str::ulid();

        // New orders must start as pending
        $data['status'] = 'pending';

        $order = Order::create($data);

        return response()->json([
            'message' => 'Order created successfully.',
            'order' => $order,
        ], 201);
    }
}