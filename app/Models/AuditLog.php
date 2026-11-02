<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'target_type', 'target_id', 'metadata', 'ip'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Never pass secrets, tokens or image data in $metadata. */
    public static function record(?int $userId, string $action, ?string $targetType = null, string|int|null $targetId = null, array $metadata = []): void
    {
        try {
            static::create([
                'user_id' => $userId, 'action' => $action, 'target_type' => $targetType,
                'target_id' => $targetId === null ? null : (string) $targetId, 'metadata' => $metadata ?: null,
                'ip' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the user's request.
            report($e);
        }
    }
}
