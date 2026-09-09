<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'role_label' => match ($this->role) {
                'admin' => 'Administrador', 'treasury' => 'Tesorería', default => 'Cliente'
            },
            'is_active' => (bool) $this->is_active,
            'can_deliver' => $this->when($request->is('api/v1/admin/*'), (bool) $this->can_deliver),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
