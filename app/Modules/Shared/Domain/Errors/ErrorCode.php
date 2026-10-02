<?php

namespace App\Modules\Shared\Domain\Errors;

/**
 * Every code a client can encounter, one case per entry in
 * docs/openapi/error-catalog.yaml (spec 003). The catalogue is the source of
 * truth: tests/Contract/OpenApi/ErrorCodeParityTest.php fails when this enum,
 * its statuses, titles or retry classes drift from it.
 *
 * A persistence-free primitive, so it lives in the shared kernel where every
 * module's refusals and the HTTP runtime can reach it (spec 007, R-15).
 */
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case MalformedJson = 'MALFORMED_JSON';
    case InvalidQueryParameter = 'INVALID_QUERY_PARAMETER';
    case InvalidCursor = 'INVALID_CURSOR';
    case FilterFieldNotSupported = 'FILTER_FIELD_NOT_SUPPORTED';
    case FilterOperatorNotSupported = 'FILTER_OPERATOR_NOT_SUPPORTED';
    case SortFieldNotSupported = 'SORT_FIELD_NOT_SUPPORTED';
    case IdempotencyKeyRequired = 'IDEMPOTENCY_KEY_REQUIRED';
    case IdempotencyKeyInvalid = 'IDEMPOTENCY_KEY_INVALID';
    case ClientVersionRequired = 'CLIENT_VERSION_REQUIRED';
    case ClientVersionInvalid = 'CLIENT_VERSION_INVALID';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case FeatureNotEntitled = 'FEATURE_NOT_ENTITLED';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case NotAcceptable = 'NOT_ACCEPTABLE';
    case VersionConflict = 'VERSION_CONFLICT';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case IdempotencyRequestInProgress = 'IDEMPOTENCY_REQUEST_IN_PROGRESS';
    case SyncCursorExpired = 'SYNC_CURSOR_EXPIRED';
    case EndpointRemoved = 'ENDPOINT_REMOVED';
    case UnsupportedMediaType = 'UNSUPPORTED_MEDIA_TYPE';
    case InvalidId = 'INVALID_ID';
    case ClientVersionUnsupported = 'CLIENT_VERSION_UNSUPPORTED';
    case RateLimited = 'RATE_LIMITED';
    case InternalError = 'INTERNAL_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';
    case Required = 'REQUIRED';
    case UnknownField = 'UNKNOWN_FIELD';
    case InvalidType = 'INVALID_TYPE';
    case InvalidEnumValue = 'INVALID_ENUM_VALUE';
    case OutOfRange = 'OUT_OF_RANGE';
    case AmountNotInteger = 'AMOUNT_NOT_INTEGER';
    case InvalidCurrency = 'INVALID_CURRENCY';
    case DateNotCalendarDate = 'DATE_NOT_CALENDAR_DATE';
    case InstantNotUtc = 'INSTANT_NOT_UTC';
    case ImmutableField = 'IMMUTABLE_FIELD';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AccountLocked = 'ACCOUNT_LOCKED';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case VerificationTokenInvalid = 'VERIFICATION_TOKEN_INVALID';
    case ResetTokenInvalid = 'RESET_TOKEN_INVALID';
    case TwoFactorRequired = 'TWO_FACTOR_REQUIRED';
    case TwoFactorInvalid = 'TWO_FACTOR_INVALID';
    case InsecureTransport = 'INSECURE_TRANSPORT';
    case ProviderSignatureInvalid = 'PROVIDER_SIGNATURE_INVALID';
    case ExportInProgress = 'EXPORT_IN_PROGRESS';
    case EmailAlreadyRegistered = 'EMAIL_ALREADY_REGISTERED';
    case CategoryInUse = 'CATEGORY_IN_USE';

    /**
     * The single HTTP status this code is served with.
     */
    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed,
            self::InvalidId,
            self::Required,
            self::UnknownField,
            self::InvalidType,
            self::InvalidEnumValue,
            self::OutOfRange,
            self::AmountNotInteger,
            self::InvalidCurrency,
            self::DateNotCalendarDate,
            self::InstantNotUtc,
            self::ImmutableField => 422,
            self::MalformedJson,
            self::InvalidQueryParameter,
            self::InvalidCursor,
            self::FilterFieldNotSupported,
            self::FilterOperatorNotSupported,
            self::SortFieldNotSupported,
            self::IdempotencyKeyRequired,
            self::IdempotencyKeyInvalid,
            self::ClientVersionRequired,
            self::ClientVersionInvalid,
            self::VerificationTokenInvalid,
            self::ResetTokenInvalid,
            self::InsecureTransport => 400,
            self::Unauthenticated,
            self::InvalidCredentials,
            self::TwoFactorRequired,
            self::TwoFactorInvalid,
            self::ProviderSignatureInvalid => 401,
            self::Forbidden,
            self::FeatureNotEntitled,
            self::EmailNotVerified => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::NotAcceptable => 406,
            self::VersionConflict,
            self::IdempotencyKeyReused,
            self::IdempotencyRequestInProgress,
            self::ExportInProgress,
            self::EmailAlreadyRegistered,
            self::CategoryInUse => 409,
            self::SyncCursorExpired,
            self::EndpointRemoved => 410,
            self::UnsupportedMediaType => 415,
            self::ClientVersionUnsupported => 426,
            self::RateLimited => 429,
            self::InternalError => 500,
            self::ServiceUnavailable => 503,
            self::AccountLocked => 423,
        };
    }

    /**
     * The catalogue's English label, copied into Problem.title; never localised.
     */
    public function title(): string
    {
        return match ($this) {
            self::ValidationFailed => 'Validation failed',
            self::MalformedJson => 'Malformed JSON',
            self::InvalidQueryParameter => 'Invalid query parameter',
            self::InvalidCursor => 'Invalid cursor',
            self::FilterFieldNotSupported => 'Filter field not supported',
            self::FilterOperatorNotSupported => 'Filter operator not supported',
            self::SortFieldNotSupported => 'Sort field not supported',
            self::IdempotencyKeyRequired => 'Idempotency key required',
            self::IdempotencyKeyInvalid => 'Idempotency key invalid',
            self::ClientVersionRequired => 'Client version required',
            self::ClientVersionInvalid => 'Client version invalid',
            self::Unauthenticated => 'Unauthenticated',
            self::Forbidden => 'Forbidden',
            self::FeatureNotEntitled => 'Feature not entitled',
            self::NotFound => 'Not found',
            self::MethodNotAllowed => 'Method not allowed',
            self::NotAcceptable => 'Not acceptable',
            self::VersionConflict => 'Version conflict',
            self::IdempotencyKeyReused => 'Idempotency key reused',
            self::IdempotencyRequestInProgress => 'Idempotent request in progress',
            self::SyncCursorExpired => 'Sync cursor expired',
            self::EndpointRemoved => 'Endpoint removed',
            self::UnsupportedMediaType => 'Unsupported media type',
            self::InvalidId => 'Invalid identifier',
            self::ClientVersionUnsupported => 'Client version unsupported',
            self::RateLimited => 'Rate limited',
            self::InternalError => 'Internal error',
            self::ServiceUnavailable => 'Service unavailable',
            self::Required => 'Required',
            self::UnknownField => 'Unknown field',
            self::InvalidType => 'Invalid type',
            self::InvalidEnumValue => 'Invalid enum value',
            self::OutOfRange => 'Out of range',
            self::AmountNotInteger => 'Amount not an integer',
            self::InvalidCurrency => 'Invalid currency',
            self::DateNotCalendarDate => 'Date is not a calendar date',
            self::InstantNotUtc => 'Instant not UTC',
            self::ImmutableField => 'Immutable field',
            self::InvalidCredentials => 'Invalid credentials',
            self::AccountLocked => 'Account locked',
            self::EmailNotVerified => 'Address not verified',
            self::VerificationTokenInvalid => 'Verification link invalid',
            self::ResetTokenInvalid => 'Reset link invalid',
            self::TwoFactorRequired => 'Second factor required',
            self::TwoFactorInvalid => 'Second factor invalid',
            self::InsecureTransport => 'Insecure transport',
            self::ProviderSignatureInvalid => 'Provider message not verified',
            self::ExportInProgress => 'Export already in progress',
            self::EmailAlreadyRegistered => 'Email already registered',
            self::CategoryInUse => 'Category in use',
        };
    }

    /**
     * Whether a client should retry: automatic, after-change or never.
     */
    public function retryable(): string
    {
        return match ($this) {
            self::ValidationFailed,
            self::MalformedJson,
            self::InvalidQueryParameter,
            self::InvalidCursor,
            self::IdempotencyKeyRequired,
            self::IdempotencyKeyInvalid,
            self::ClientVersionRequired,
            self::ClientVersionInvalid,
            self::VersionConflict,
            self::SyncCursorExpired,
            self::Required,
            self::UnknownField,
            self::InvalidType,
            self::InvalidEnumValue,
            self::OutOfRange,
            self::AmountNotInteger,
            self::InvalidCurrency,
            self::DateNotCalendarDate,
            self::InstantNotUtc,
            self::ImmutableField,
            self::VerificationTokenInvalid,
            self::ResetTokenInvalid,
            self::TwoFactorRequired,
            self::TwoFactorInvalid => 'after-change',
            self::FilterFieldNotSupported,
            self::FilterOperatorNotSupported,
            self::SortFieldNotSupported,
            self::Unauthenticated,
            self::Forbidden,
            self::FeatureNotEntitled,
            self::NotFound,
            self::MethodNotAllowed,
            self::NotAcceptable,
            self::IdempotencyKeyReused,
            self::EndpointRemoved,
            self::UnsupportedMediaType,
            self::InvalidId,
            self::ClientVersionUnsupported,
            self::InvalidCredentials,
            self::EmailNotVerified,
            self::InsecureTransport,
            self::ProviderSignatureInvalid,
            self::EmailAlreadyRegistered,
            self::CategoryInUse => 'never',
            self::IdempotencyRequestInProgress,
            self::RateLimited,
            self::InternalError,
            self::ServiceUnavailable,
            self::AccountLocked,
            self::ExportInProgress => 'automatic',
        };
    }

    /**
     * Whether this code appears only inside errors[] of a VALIDATION_FAILED.
     */
    public function isFieldLevelOnly(): bool
    {
        return in_array($this, [
            self::Required,
            self::UnknownField,
            self::InvalidType,
            self::InvalidEnumValue,
            self::OutOfRange,
            self::AmountNotInteger,
            self::InvalidCurrency,
            self::DateNotCalendarDate,
            self::InstantNotUtc,
            self::ImmutableField,
        ], true);
    }
}
