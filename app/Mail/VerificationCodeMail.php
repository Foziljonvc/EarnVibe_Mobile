<?php

namespace App\Mail;

use App\Models\v1\User;
use Illuminate\Mail\Mailable;

class VerificationCodeMail extends Mailable
{
    public $code;
    public $type;
    public $user;

    public function __construct(string $code, string $type, User $user)
    {
        $this->code = $code;
        $this->type = $type;
        $this->user = $user;
    }

    public function build()
    {
        $subject = $this->type === 'email_change'
            ? 'Email Change Verification'
            : 'Password Change Verification';

        return $this->markdown('emails.verification-code')
            ->subject($subject);
    }
}

