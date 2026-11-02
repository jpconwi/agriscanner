<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoredFile extends Model
{
    public const PENDING = 'pending';

    public const AVAILABLE = 'available';

    public const FAILED = 'failed';

    public const DELETING = 'deleting';

    protected $fillable = ['uuid', 'user_id', 'object_key', 'mime', 'size_bytes', 'sha256', 'width', 'height', 'status', 'failure_reason'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replicas(): HasMany
    {
        return $this->hasMany(StoredFileReplica::class);
    }
}
