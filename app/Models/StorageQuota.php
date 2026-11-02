<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageQuota extends Model
{
    protected $fillable = ['user_id', 'quota_bytes'];

    protected function casts(): array
    {
        return ['quota_bytes' => 'integer'];
    }
}
