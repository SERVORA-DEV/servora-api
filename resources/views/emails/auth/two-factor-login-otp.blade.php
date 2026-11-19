@component('mail::message')
# Your sign-in code

Enter this code to finish signing in to your SERVORA administrator account.

@component('mail::code')
{{ $otp }}
@endcomponent

The code expires in {{ $expiresInMinutes }} minutes. Don't share it with anyone. SERVORA will never ask you for it.

The SERVORA team

@slot('subcopy')
If you didn't just try to sign in, change your password now. Someone else may know it.
@endslot
@endcomponent
