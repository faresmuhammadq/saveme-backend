{{-- Identity mail layout (spec 007): direction follows the language; numerals stay Western (P-14). --}}
@props(['mailKey'])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __("identity::mail.{$mailKey}.subject", ['app' => config('app.name')]) }}</title>
</head>
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #1f2933;">
    <p>{{ __('identity::mail.greeting') }}</p>
    {{ $slot }}
    <p>{{ __('identity::mail.signature', ['app' => config('app.name')]) }}</p>
</body>
</html>
