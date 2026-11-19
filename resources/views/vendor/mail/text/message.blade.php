@php
    $site = rtrim((string) config('app.frontend_url'), '/');
    $support = trim((string) config('legal.operator.email'));
    $support = str_contains($support, '@') && ! str_starts_with($support, '[') ? $support : null;
@endphp
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="$site">
            SERVORA
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
SERVORA · Spa management made simple
@if ($support)
Questions? Write to {{ $support }}
@endif
This is an automated message.
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
