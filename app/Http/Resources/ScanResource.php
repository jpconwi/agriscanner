<?php

namespace App\Http\Resources;

use App\Services\Storage\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The public id is the UUID: not guessable or enumerable. Image URLs are short-lived signed URLs to a private bucket. */
class ScanResource extends JsonResource
{
    public const DISCLAIMER = 'This is an AI-generated suggestion and not a confirmed diagnosis. Chemical products and rates must follow the product label and local agricultural guidance; consult an agronomist or extension officer before treating.';

    public function toArray(Request $request): array
    {
        $r = $this->result;
        $rec = $r?->recommendations ?? [];
        $url = $this->file ? app(StorageManager::class)->temporaryUrl($this->file) : null;

        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'failure_message' => $this->failure_message,
            'is_healthy' => (bool) $r?->is_healthy,
            'predicted_label' => $r?->predicted_label,
            'crop_label' => $r?->crop_label,
            'crop' => ($r?->crop ?? $this->crop) ? ['id' => ($r?->crop ?? $this->crop)->id, 'name' => ($r?->crop ?? $this->crop)->name] : null,
            'disease' => $r?->disease ? ['id' => $r->disease->id, 'name' => $r->disease->name, 'severity' => $r->disease->severity] : null,
            // null unless the model genuinely returned a probability
            'confidence' => $r?->confidence,
            'provider' => $r?->provider,
            'model' => $r?->model,
            'alternatives' => $r?->alternatives,
            'symptoms' => $rec['symptoms'] ?? [],
            'contributing_factors' => $rec['contributing_factors'] ?? [],
            'treatments' => $rec['treatments'] ?? [],
            'prevention' => $rec['prevention'] ?? [],
            'follow_up' => $rec['follow_up'] ?? null,
            'disclaimer' => ScanResource::DISCLAIMER,
            'image_url' => $url,
            'thumbnail_url' => $url,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
