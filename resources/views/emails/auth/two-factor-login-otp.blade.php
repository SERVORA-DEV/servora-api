@component('mail::message')
# Your Sign-In Code

Someone is signing in to your {{ config('app.name') }} administrator account and requested a code sent to your verified personal email instead of an authenticator app. Use the code below to finish signing in. This code expires in {{ $expiresInMinutes }} minutes.

@component('mail::panel')
<div style="text-align: center; font-size: 28px; letter-spacing: 8px; font-weight: bold;">
{{ $otp }}
</div>
@endcomponent

If this wasn't you, change your password immediately — someone else may have it.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
