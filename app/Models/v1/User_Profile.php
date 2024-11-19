<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class User_Profile extends Model
{
    use HasFactory, Notifiable;
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'phone_number',
        'avatar_url',
        'bio',
        'total_coins_earned',
        'total_coins_spent',
        'current_coins',
        'total_videos_watched',
        'total_watch_time'
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
