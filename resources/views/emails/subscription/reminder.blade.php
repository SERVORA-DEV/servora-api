@component('mail::message')
# {{ $heading }}

{{ $body }}

@component('mail::button', ['url' => $actionUrl])
{{ $actionLabel }}
@endcomponent

The SERVORA team

@slot('subcopy')
@if ($alwaysSent)
You're receiving this because it affects your access to SERVORA.
@else
You can turn off reminders before your due date in Business Settings → Notifications. Overdue notices are always sent.
@endif
@endslot
@endcomponent
