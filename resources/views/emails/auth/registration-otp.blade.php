@component('mail::message')
# Confirm your email

Enter this code in the SERVORA app to finish creating your account.

@component('mail::code')
{{ $otp }}
@endcomponent

The code expires in {{ $expiresInMinutes }} minutes. Don't share it with anyone. SERVORA will never ask you for it.

The SERVORA team

@slot('subcopy')
If you didn't sign up for SERVORA, you can ignore this email.
@endslot
@endcomponent
