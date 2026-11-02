<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanResult extends Model
{
    protected $fillable = [
        'scan_id', 'disease_id', 'crop_id', 'predicted_label', 'crop_label', 'is_healthy', 'confidence',
        'provider', 'model', 'latency_ms', 'recommendations', 'alternatives',
    ];

    protected function casts(): array
    {
        return [
            'is_healthy' => 'boolean',
            'confidence' => 'float',
            'recommendations' => 'array',
            'alternatives' => 'array',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function disease(): BelongsTo
    {
        return $this->belongsTo(Disease::class);
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }
}
