@component('mail::message')
# Confirm your email

Someone added **{{ $email }}** as the contact email for {{ $what }}, **{{ $name }}**, on {{ config('app.name') }}.

Press the button to confirm it. It takes one click, and there is nothing to type.

@component('mail::button', ['url' => $url])
Confirm this email
@endcomponent

The button works for 2 days. If you didn't expect this, ignore this email and nothing will change.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
