@component('mail::message')
# Confirm your email

Thanks for signing up for {{ config('app.name') }}! Press the button to confirm your email. Your account is created when you do.

@component('mail::button', ['url' => $url])
Confirm my email
@endcomponent

The button works for {{ $hours }} hours. If you did not sign up, ignore this email and no account will be created.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
