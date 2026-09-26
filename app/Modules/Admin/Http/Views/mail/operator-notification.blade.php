{{-- Operator mail (spec 007, FR-056): identifiers and times only. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), config('admin.rtl_locales'), true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('admin::notifications.kinds.deletion_cancelled_by_sign_in.title') }}</title>
</head>
<body style="font-family: system-ui, sans-serif; line-height: 1.5;">
    <p>{{ __('admin::notifications.kinds.deletion_cancelled_by_sign_in.body') }}</p>
    <p>{{ __('admin::notifications.mail.user') }} <bdi dir="ltr"><code>{{ $userId }}</code></bdi></p>
    <p>{{ __('admin::notifications.mail.when') }} <bdi dir="ltr">{{ $utc }}</bdi> (<bdi dir="ltr">{{ $local }}</bdi>)</p>
    <p>{{ __('admin::notifications.mail.lookup') }}</p>
</body>
</html>
