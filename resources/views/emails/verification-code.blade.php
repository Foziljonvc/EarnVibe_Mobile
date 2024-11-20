@component('mail::message')
    # Verification Required

    Hi {{ $user->name }},

    @if($type === 'email_change')
        You have requested to change your email address.
    @else
        You have requested to change your password.
    @endif

    Your verification code is:

    @component('mail::panel')
        {{ $code }}
    @endcomponent

    This code will expire in 10 minutes.

    If you didn't request this change, please ignore this email.

    Thanks,<br>
    {{ config('app.name') }}
@endcomponent
