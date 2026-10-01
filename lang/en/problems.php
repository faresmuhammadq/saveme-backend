<?php

/*
 * Problem `detail` sentences, one per response-level code in
 * docs/openapi/error-catalog.yaml, and one per field-level code under
 * `fields`. Clients show these; they never branch on them (spec 003).
 */
return [

    'VALIDATION_FAILED' => 'Some of the information sent is not valid. Each problem is listed.',
    'MALFORMED_JSON' => 'The request could not be read. Send a JSON object.',
    'INVALID_QUERY_PARAMETER' => 'A query parameter cannot be used as sent.',
    'INVALID_CURSOR' => 'The page cursor is not valid. Start again from the first page.',
    'FILTER_FIELD_NOT_SUPPORTED' => 'This list cannot be filtered by that field.',
    'FILTER_OPERATOR_NOT_SUPPORTED' => 'That filter operator is not allowed for this field.',
    'SORT_FIELD_NOT_SUPPORTED' => 'This list cannot be sorted by that field.',
    'IDEMPOTENCY_KEY_REQUIRED' => 'This request needs an Idempotency-Key header.',
    'IDEMPOTENCY_KEY_INVALID' => 'The Idempotency-Key header is not valid.',
    'CLIENT_VERSION_REQUIRED' => 'The app did not say which version it is.',
    'CLIENT_VERSION_INVALID' => 'The app version or platform is not recognised.',
    'UNAUTHENTICATED' => 'Please sign in again.',
    'FORBIDDEN' => 'Your role in this wallet does not allow this.',
    'FEATURE_NOT_ENTITLED' => 'Your plan does not include this.',
    'NOT_FOUND' => 'We could not find that.',
    'METHOD_NOT_ALLOWED' => 'This action is not available here.',
    'NOT_ACCEPTABLE' => 'This service answers in JSON only.',
    'VERSION_CONFLICT' => 'Someone changed this in the meantime. Review the latest version and try again.',
    'IDEMPOTENCY_KEY_REUSED' => 'This request was already made with different details.',
    'IDEMPOTENCY_REQUEST_IN_PROGRESS' => 'The original request is still being handled. Try again shortly.',
    'SYNC_CURSOR_EXPIRED' => 'This device has been offline too long to catch up. Syncing everything again.',
    'ENDPOINT_REMOVED' => 'This version of the app is no longer supported. Please update.',
    'UNSUPPORTED_MEDIA_TYPE' => 'Send the request body as JSON.',
    'INVALID_ID' => 'The identifier is not valid.',
    'CLIENT_VERSION_UNSUPPORTED' => 'Please update the app to continue.',
    'RATE_LIMITED' => 'Too many attempts. Please wait a moment and try again.',
    'INTERNAL_ERROR' => 'Something went wrong on our side. Please try again.',
    'SERVICE_UNAVAILABLE' => 'The service is temporarily unavailable. Please try again soon.',
    'INVALID_CREDENTIALS' => 'The email address or password is incorrect.',
    'ACCOUNT_LOCKED' => 'Too many failed attempts. Wait a while, or reset your password to get back in now.',
    'EMAIL_NOT_VERIFIED' => 'Verify your email address first. We can send the link again.',
    'VERIFICATION_TOKEN_INVALID' => 'This verification link has expired or was already used. Request a new one.',
    'RESET_TOKEN_INVALID' => 'This reset link has expired or was already used. Request a new one.',
    'TWO_FACTOR_REQUIRED' => 'Enter the code from your authenticator app.',
    'TWO_FACTOR_INVALID' => 'That code is not correct. Try the current one, or a recovery code.',
    'INSECURE_TRANSPORT' => 'This connection is not secure. Use HTTPS.',
    'PROVIDER_SIGNATURE_INVALID' => 'The payment message could not be verified.',
    'EXPORT_IN_PROGRESS' => 'An export is already being prepared. Wait for it to finish.',
    'EMAIL_ALREADY_REGISTERED' => 'You already have an account with this email address. Sign in, or reset your password if you forgot it.',

    'default_currency_locked' => 'The default currency can\'t change once you have recorded transactions.',
    'wallet_type_immutable' => 'The type of a wallet can\'t be changed.',
    'wallet_currency_locked' => 'The currency can\'t change once the wallet has transactions.',
    'wallet_ownership_target' => 'Choose another member of this wallet to hand it to.',

    'fields' => [
        'REQUIRED' => 'This field is required.',
        'UNKNOWN_FIELD' => 'This field is not accepted here.',
        'INVALID_TYPE' => 'This value is not in the expected form.',
        'INVALID_ENUM_VALUE' => 'This value is not one of the allowed options.',
        'OUT_OF_RANGE' => 'This value is too short, too long, or outside the allowed range.',
        'AMOUNT_NOT_INTEGER' => 'Amounts must be whole numbers of minor units.',
        'INVALID_CURRENCY' => 'Use an uppercase three-letter currency code.',
        'DATE_NOT_CALENDAR_DATE' => 'Use a date in the form YYYY-MM-DD, with no time.',
        'INSTANT_NOT_UTC' => 'Use a UTC time ending in Z.',
        'INVALID_ID' => 'The identifier is not valid.',
        'IMMUTABLE_FIELD' => 'This field cannot be changed.',
    ],

];
