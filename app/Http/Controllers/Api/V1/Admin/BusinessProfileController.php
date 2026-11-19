<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBusinessProfileRequest;
use App\Http\Resources\AdminBusinessProfileResource;
use App\Services\BusinessProfileService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class BusinessProfileController extends Controller
{
    public function __construct(private BusinessProfileService $profiles) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => (new AdminBusinessProfileResource($this->profiles->current()))->resolve()]);
    }

    public function update(UpdateBusinessProfileRequest $request): JsonResponse
    {
        try {
            $profile = $this->profiles->save($request->permittedFields(), $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['logo' => [$exception->getMessage()]]], 422);
        }

        return response()->json([
            'message' => 'Datos del negocio guardados correctamente.',
            'data' => (new AdminBusinessProfileResource($profile))->resolve(),
        ]);
    }
}
