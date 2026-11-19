@component('mail::message')
# Reset your password

We received a request to reset the password for your SERVORA account. Enter this code to continue.

@component('mail::code')
{{ $otp }}
@endcomponent

The code expires in {{ $expiresInMinutes }} minutes. Don't share it with anyone. SERVORA will never ask you for it.

The SERVORA team

@slot('subcopy')
If you didn't ask to reset your password, you can ignore this email. Your password stays the same.
@endslot
@endcomponent
