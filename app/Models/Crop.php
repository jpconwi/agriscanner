<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Crop extends Model
{
    protected $fillable = ['name', 'slug', 'scientific_name', 'description'];

    protected static function booted(): void
    {
        static::saving(function (Crop $crop) {
            $crop->slug = Str::slug($crop->name);
        });
    }

    public function diseases(): HasMany
    {
        return $this->hasMany(Disease::class);
    }
}
