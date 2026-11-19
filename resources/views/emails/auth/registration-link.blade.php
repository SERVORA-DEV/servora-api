@component('mail::message')
# Confirm your email

You're almost done. Confirm this email address and your SERVORA business account will be created.

@component('mail::button', ['url' => $url])
Confirm email
@endcomponent

The link expires in {{ $hours }} hours.

The SERVORA team

@slot('subcopy')
If you didn't sign up for SERVORA, ignore this email and no account will be created.
@endslot
@endcomponent
