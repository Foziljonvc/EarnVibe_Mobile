<?php

namespace app\Services\VerificationService;
use App\Models\v1\User;
use App\Models\v1\VerificationCode;

class VerificationService
{
    public function generateCode(): string
    {
        return str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function createVerification(
        User $user,
        string $type,
        array $data = []
    ): VerificationCode {
        VerificationCode::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_used', false)
            ->delete();

        return VerificationCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'code' => $this->generateCode(),
            'type' => $type,
            'data' => $data,
            'expires_at' => now()->addMinutes(10)
        ]);
    }

    public function verify(
        User $user,
        string $code,
        string $type
    ): ?VerificationCode {
        $verification = VerificationCode::where('user_id', $user->id)
            ->where('code', $code)
            ->where('type', $type)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->first();

        if ($verification) {
            $verification->update(['is_used' => true]);
        }

        return $verification;
    }
}
