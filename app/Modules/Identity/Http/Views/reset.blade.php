<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @if ($valid)
        <meta http-equiv="refresh" content="1;url={{ $appUrl }}">
    @endif
    <title>{{ __('identity::pages.reset.title') }}</title>
</head>
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #1f2933; max-width: 28rem; margin: 4rem auto; padding: 0 1rem; text-align: center;">
    <h1>{{ $valid ? __('identity::pages.reset.ok') : __('identity::pages.reset.bad') }}</h1>
    <p>{{ $valid ? __('identity::pages.reset.ok_body') : __('identity::pages.reset.bad_body') }}</p>
    @if ($valid)
        <p><a href="{{ $appUrl }}" dir="ltr">{{ __('identity::pages.reset.open') }}</a></p>
    @endif
</body>
</html>
