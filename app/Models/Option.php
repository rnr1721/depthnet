<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    protected $fillable = [
        'key', 'value', 'type', 'description', 'is_system'
    ];
}
