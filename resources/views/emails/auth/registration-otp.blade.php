@component('mail::message')
# Verify Your Email

Thanks for signing up for {{ config('app.name') }}! Use the code below to verify your email address. This code expires in {{ $expiresInMinutes }} minutes.

@component('mail::panel')
<div style="text-align: center; font-size: 28px; letter-spacing: 8px; font-weight: bold;">
{{ $otp }}
</div>
@endcomponent

If you did not create this account, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
