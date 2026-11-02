<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageUsage extends Model
{
    protected $table = 'storage_usage';

    protected $fillable = ['user_id', 'used_bytes', 'reserved_bytes', 'file_count', 'last_reconciled_at'];

    protected function casts(): array
    {
        return ['used_bytes' => 'integer', 'reserved_bytes' => 'integer', 'file_count' => 'integer', 'last_reconciled_at' => 'datetime'];
    }
}
