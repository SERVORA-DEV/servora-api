@component('mail::message')
# Confirm your recovery email

This address was added as the personal recovery email for a SERVORA administrator account. Enter this code to confirm it's yours.

@component('mail::code')
{{ $otp }}
@endcomponent

The code expires in {{ $expiresInMinutes }} minutes. Don't share it with anyone. SERVORA will never ask you for it.

The SERVORA team

@slot('subcopy')
If you didn't add this address, you can ignore this email. The account won't use it.
@endslot
@endcomponent
