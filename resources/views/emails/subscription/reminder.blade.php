@component('mail::message')
# {{ $heading }}

{{ $body }}

@component('mail::button', ['url' => $actionUrl])
{{ $actionLabel }}
@endcomponent

@if ($alwaysSent)
You are getting this because your access depends on it.
@else
You can switch off the reminders sent before the due date in Business Settings, under Notifications. If a payment becomes overdue you are always told.
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
