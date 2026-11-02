<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiseaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = $this->recommendationSnapshot();

        return [
            'id' => $this->id,
            'crop_id' => $this->crop_id,
            'crop' => $this->crop ? ['id' => $this->crop->id, 'name' => $this->crop->name] : null,
            'name' => $this->name,
            'description' => $this->description,
            'severity' => $this->severity,
        ] + $s;
    }
}
