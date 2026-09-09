<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfilePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(): UserResource
    {
        return new UserResource(request()->user());
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $data = $request->validated();
        $user->fill(['name' => trim($data['name']), 'email' => $data['email'], 'phone' => $data['phone'] ?? null])->save();

        return new UserResource($user->refresh());
    }

    public function password(UpdateProfilePasswordRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['La contraseña actual es incorrecta.']]);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['La nueva contraseña debe ser diferente a la actual.']]);
        }
        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => Hash::make($data['password'])])->save();
            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión nuevamente.', 'requires_login' => true]);
    }
}
