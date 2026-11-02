<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvisoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $full = $request->routeIs('advisories.show') || $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'summary' => $this->summary,
            'body' => $this->when($full, $this->body),
            'is_published' => (bool) $this->is_published,
            'published_at' => $this->published_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
