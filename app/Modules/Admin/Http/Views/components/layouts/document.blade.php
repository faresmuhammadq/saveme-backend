{{--
    The back-office HTML document: language, direction, tokens and scripts
    (spec 006, FR-030). The shell wraps this; nothing else renders <html>.
--}}
@props(['title'])

@php
    $locale = app()->getLocale();
    $direction = in_array($locale, config('admin.rtl_locales'), true) ? 'rtl' : 'ltr';

    // A font missing from the build falls back to system fonts rather than failing the page.
    try {
        $fonts = \Illuminate\Support\Facades\Vite::fonts(['hanken-grotesk', 'ibm-plex-sans-arabic']);
    } catch (\Illuminate\Foundation\ViteException) {
        $fonts = '';
    }
@endphp

<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    {{ $fonts }}
    @vite(['app/Modules/Admin/Http/Assets/admin.css', 'app/Modules/Admin/Http/Assets/admin.js'])
    @livewireStyles
</head>
<body class="bg-canvas font-sans text-body text-ink tabular-nums antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
