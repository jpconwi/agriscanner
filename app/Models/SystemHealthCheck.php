<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class SystemHealthCheck extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['service', 'status', 'latency_ms', 'message', 'details', 'checked_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'checked_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('checked_at', '<', now()->subDays(30));
    }
}
