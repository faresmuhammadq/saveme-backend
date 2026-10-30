<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @if ($verified)
        <meta http-equiv="refresh" content="2;url={{ $appUrl }}">
    @endif
    <title>{{ __('identity::pages.verified.title') }}</title>
</head>
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #1f2933; max-width: 28rem; margin: 4rem auto; padding: 0 1rem; text-align: center;">
    <h1>{{ $verified ? __('identity::pages.verified.ok') : __('identity::pages.verified.bad') }}</h1>
    <p>{{ $verified ? __('identity::pages.verified.ok_body') : __('identity::pages.verified.bad_body') }}</p>
    <p><a href="{{ $appUrl }}" dir="ltr">{{ __('identity::pages.verified.open') }}</a></p>
</body>
</html>
