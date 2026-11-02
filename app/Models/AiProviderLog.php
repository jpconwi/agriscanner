<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class AiProviderLog extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'scan_id', 'provider', 'model', 'attempt', 'outcome', 'error_category', 'http_status', 'latency_ms'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(60));
    }
}
