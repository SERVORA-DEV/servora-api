@component('mail::message')
# Turn on two-factor sign-in

Enter this code to turn on two-factor sign-in for your SERVORA administrator account. After that, we'll email you a code each time you sign in.

@component('mail::code')
{{ $otp }}
@endcomponent

The code expires in {{ $expiresInMinutes }} minutes. Don't share it with anyone. SERVORA will never ask you for it.

The SERVORA team

@slot('subcopy')
If you didn't ask for this, you can ignore this email. Nothing changes without the code.
@endslot
@endcomponent
