<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => [
                'required',
                'string',
                'max:100'
            ],
        ]);

        $user = User::where(
            'email',
            $data['email']
        )->first();

        if (
            !$user ||
            !Hash::check(
                $data['password'],
                $user->password
            )
        ) {
            return response()->json([
                'message' => 'Invalid credentials.'
            ], 401);
        }

        // Only supported roles can log in
        if (!in_array(
            $user->role,
            ['admin', 'driver'],
            true
        )) {
            return response()->json([
                'message' => 'Access denied.'
            ], 403);
        }

        // Driver must have an active profile
        if ($user->role === 'driver') {
            $driver = $user->driver;

            if (!$driver || !$driver->is_active) {
                return response()->json([
                    'message' => 'Driver account inactive.'
                ], 403);
            }
        }

        $token = $user->createToken(
            $data['device_name'],
            [$user->role],
            now()->addDays(7)
        )->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only([
                'id',
                'name',
                'email',
                'role',
            ]),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->only([
                'id',
                'name',
                'email',
                'role',
            ]),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()
            ->currentAccessToken()
            ->delete();

        return response()->json([
            'message' => 'Logout successful.'
        ]);
    }
}