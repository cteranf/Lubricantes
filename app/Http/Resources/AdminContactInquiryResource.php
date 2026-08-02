<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminContactInquiryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'subject' => $this->subject,
            'message' => $this->message,
            'status' => $this->status,
            'source' => $this->source,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'attention_started_at' => $this->attention_started_at?->toIso8601String(),
            'attended_at' => $this->attended_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($note) => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->user ? ['id' => $note->user->id, 'name' => $note->user->name] : null,
                'created_at' => $note->created_at?->toIso8601String(),
            ])),
            'history' => $this->whenLoaded('histories', fn () => $this->histories->map(fn ($entry) => [
                'id' => $entry->id,
                'event_type' => $entry->event_type,
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'metadata' => $entry->metadata,
                'actor' => $entry->actor ? ['id' => $entry->actor->id, 'name' => $entry->actor->name] : null,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])),
        ];
    }
}
