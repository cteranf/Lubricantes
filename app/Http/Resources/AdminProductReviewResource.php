<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'product' => $this->product ? ['id' => (int) $this->product->id, 'name' => (string) $this->product->name] : null,
            'customer_name' => $this->user ? (string) $this->user->name : 'Cliente eliminado',
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'verified_purchase' => $this->verified_purchase_at !== null,
            'status' => (string) $this->status,
            'rejection_reason' => $this->status === 'rejected' ? $this->rejection_reason : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
