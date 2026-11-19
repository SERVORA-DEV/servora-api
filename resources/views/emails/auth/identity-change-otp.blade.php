@component('mail::message')
# Confirm your new email

You asked to change the email on your SERVORA owner account to this address. Enter this code to confirm it.

@component('mail::code')
{{ $otp }}
@endcomponent

The code expires in {{ $expiresInMinutes }} minutes. Don't share it with anyone. SERVORA will never ask you for it.

Your email changes only after the SERVORA team reviews your new ID and face scan.

The SERVORA team

@slot('subcopy')
If you didn't ask for this, you can ignore this email. Your email stays the same.
@endslot
@endcomponent
