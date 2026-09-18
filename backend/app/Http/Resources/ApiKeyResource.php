<?php

namespace App\Http\Resources;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApiKey */
class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'last_used_at' => $this->last_used_at,
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            'active' => $this->isActive(),
            'created_at' => $this->created_at,
        ];
    }
}
