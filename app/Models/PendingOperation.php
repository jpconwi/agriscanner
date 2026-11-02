<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingOperation extends Model
{
    public const MAX_ATTEMPTS = 8;

    protected $fillable = ['uuid', 'user_id', 'type', 'idempotency_key', 'payload', 'status', 'attempts', 'last_error', 'available_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
