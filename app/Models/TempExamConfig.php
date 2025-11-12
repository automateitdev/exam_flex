<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TempExamConfig extends Model
{
    protected $fillable = [
        'temp_id',
        'institute_id',
        'config',
        'expires_at',
    ];

    protected $casts = [
        'config' => 'array', // অটো json_decode
        'expires_at' => 'datetime',
    ];
}
