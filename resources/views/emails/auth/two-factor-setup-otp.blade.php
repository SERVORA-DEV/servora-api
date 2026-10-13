@component('mail::message')
# Turn On Two-Factor Authentication

Use the code below to turn on two-factor authentication for your {{ config('app.name') }} administrator account. From then on, a code is sent to this address each time you sign in. This code expires in {{ $expiresInMinutes }} minutes.

@component('mail::panel')
<div style="text-align: center; font-size: 28px; letter-spacing: 8px; font-weight: bold;">
{{ $otp }}
</div>
@endcomponent

If you didn't ask for this, you can ignore this email — nothing changes without the code.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
