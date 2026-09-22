@component('mail::message')
# Verify Your Personal Email

You added this address as the personal verification email on your {{ config('app.name') }} administrator account. Use the code below to confirm it's yours. This code expires in {{ $expiresInMinutes }} minutes.

@component('mail::panel')
<div style="text-align: center; font-size: 28px; letter-spacing: 8px; font-weight: bold;">
{{ $otp }}
</div>
@endcomponent

If you did not request this, you can safely ignore this email — your account's personal email will not be changed.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
