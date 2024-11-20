<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Model;

class VerificationCode extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'code',
        'type',
        'data',
        'expires_at',
        'is_used'
    ];

    protected $casts = [
        'data' => 'array',
        'expires_at' => 'datetime',
        'is_used' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return !$this->is_used && $this->expires_at->isFuture();
    }
}
