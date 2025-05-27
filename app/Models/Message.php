<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'role',
        'content',
        'from_user_id',
        'is_visible_to_user',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'is_visible_to_user' => 'boolean',
    ];

    /**
     * Get the user who sent the message (if any)
     */
    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }
}
