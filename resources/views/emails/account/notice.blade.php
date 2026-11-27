@component('mail::message')
# {{ $heading }}

@if (filled($recordName))
<p><strong>{{ $recordName }}</strong></p>
@endif

{{ $body }}

@component('mail::button', ['url' => $actionUrl])
{{ $actionLabel }}
@endcomponent

The SERVORA team
@endcomponent
