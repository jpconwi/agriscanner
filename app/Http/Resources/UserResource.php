<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->profile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => (bool) $this->is_active,
            'profile' => $p ? [
                'phone' => $p->phone, 'farm_name' => $p->farm_name, 'location' => $p->location,
                'farm_size_ha' => $p->farm_size_ha, 'main_crops' => $p->main_crops,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
