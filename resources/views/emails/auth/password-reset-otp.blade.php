@component('mail::message')
# Password Reset Request

We received a request to reset the password for your {{ config('app.name') }} account.

Use the code below to continue. This code expires in {{ $expiresInMinutes }} minutes.

@component('mail::panel')
<div style="text-align: center; font-size: 28px; letter-spacing: 8px; font-weight: bold;">
{{ $otp }}
</div>
@endcomponent

If you did not request a password reset, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
