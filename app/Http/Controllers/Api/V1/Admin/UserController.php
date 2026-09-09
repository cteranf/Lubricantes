<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserAdministrationService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private UserAdministrationService $users) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,inactive'],
            'role' => ['nullable', 'in:admin,customer,treasury'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = User::query();
        if (filled($data['search'] ?? null)) {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($data['search']));
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
        }
        if (($data['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        }
        if (($data['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }
        if (filled($data['role'] ?? null)) {
            $query->where('role', $data['role']);
        }

        return UserResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 15)->withQueryString());
    }

    public function store(StoreUserRequest $request)
    {
        return (new UserResource($this->users->create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return new UserResource($this->users->update($request->user(), $user, $request->validated()));
    }

    public function status(UpdateUserStatusRequest $request, User $user): UserResource
    {
        return new UserResource($this->users->changeStatus($request->user(), $user, (bool) $request->validated('is_active')));
    }

    public function password(ResetUserPasswordRequest $request, User $user): UserResource
    {
        return new UserResource($this->users->resetPassword($user, $request->validated('password')));
    }
}
