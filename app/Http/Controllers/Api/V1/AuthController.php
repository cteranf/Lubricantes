<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $payload = $request->all();
        $payload['email'] = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $validator = Validator::make($payload, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
            'role' => 'customer',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve($request),
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = ['email' => mb_strtolower(trim((string) $request->input('email'))), 'password' => $request->input('password')];
        $user = User::whereRaw('LOWER(email) = ?', [$credentials['email']])->first();
        if (! $user || ! Hash::check((string) $credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid login details',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'La cuenta está desactivada.',
                'code' => 'account_inactive',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
