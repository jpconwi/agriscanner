<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiseaseSymptom extends Model
{
    public $timestamps = false;

    protected $fillable = ['disease_id', 'kind', 'text', 'position'];
}
