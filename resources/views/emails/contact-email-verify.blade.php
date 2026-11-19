@component('mail::message')
# Confirm this contact email

**{{ $email }}** was added as the contact email for {{ $what }}, **{{ $name }}**, on SERVORA. Confirm it with one click.

@component('mail::button', ['url' => $url])
Confirm email
@endcomponent

The link expires in {{ $days }} {{ $days === 1 ? 'day' : 'days' }}.

The SERVORA team

@slot('subcopy')
If you didn't expect this, you can ignore this email. Nothing will change.
@endslot
@endcomponent
