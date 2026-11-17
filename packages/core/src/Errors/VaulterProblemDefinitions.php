<?php

declare(strict_types=1);

namespace Vaulter\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 problem definitions for all `axiomasoft/vaulter-core` error codes.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class VaulterProblemDefinitions implements ProblemDefinitionProvider
{
    private const string URN_PREFIX = 'urn:packman:problem:';

    /** @return iterable<ProblemDefinition> */
    #[Override]
    public function definitions(): iterable
    {
        foreach (VaulterErrorCode::cases() as $code) {
            [$status, $title, $retryable, $category, $detailKeys] = self::metadata($code);

            yield new ProblemDefinition(
                code: $code,
                type: self::URN_PREFIX.$code->value,
                defaultStatus: $status,
                title: $title,
                retryable: $retryable,
                category: $category,
                detailKeys: $detailKeys,
            );
        }
    }

    /** @return array{0: int, 1: string, 2: bool, 3: ErrorCategory, 4: list<string>} */
    private static function metadata(VaulterErrorCode $code): array
    {
        return match ($code) {
            VaulterErrorCode::ActorRequired => [401, 'Actor authentication required', false, ErrorCategory::Authentication, []],
            VaulterErrorCode::ConcurrentUpdate => [409, 'Concurrent update conflict', true, ErrorCategory::Conflict, []],
            VaulterErrorCode::DependencyUnavailable => [503, 'Dependency unavailable', true, ErrorCategory::Unavailable, []],
            VaulterErrorCode::DuplicateRegistration => [500, 'Duplicate registration', false, ErrorCategory::Internal, []],
            VaulterErrorCode::ExtensionFailed => [500, 'Extension failed', false, ErrorCategory::Internal, []],
            VaulterErrorCode::IdempotencyKeyReused => [422, 'Idempotency key reused', false, ErrorCategory::Validation, []],
            VaulterErrorCode::InsecureProductionConfiguration => [500, 'Insecure production configuration', false, ErrorCategory::Internal, []],
            VaulterErrorCode::InternalError => [500, 'Internal Vaulter error', false, ErrorCategory::Internal, []],
            VaulterErrorCode::InvalidConfiguration => [500, 'Invalid Vaulter configuration', false, ErrorCategory::Internal, []],
            VaulterErrorCode::LeaseLost => [409, 'Outbox lease lost', true, ErrorCategory::Conflict, []],
            VaulterErrorCode::MethodNotAllowed => [405, 'Method not allowed', false, ErrorCategory::Validation, []],
            VaulterErrorCode::MissingExtension => [500, 'Missing extension provider or slot handler', false, ErrorCategory::Internal, []],
            VaulterErrorCode::MissingPackage => [500, 'Required companion package not installed', false, ErrorCategory::Internal, []],
            VaulterErrorCode::ModuleGraphInvalid => [503, 'Module graph invalid', false, ErrorCategory::Unavailable, []],
            VaulterErrorCode::ModuleNotReady => [503, 'Module not ready', false, ErrorCategory::Unavailable, []],
            VaulterErrorCode::NodeSequenceExhausted => [409, 'Node sequence exhausted', false, ErrorCategory::Conflict, []],
            VaulterErrorCode::OperationInProgress => [409, 'Operation already in progress', true, ErrorCategory::Conflict, []],
            VaulterErrorCode::PayloadTooLarge => [413, 'Payload too large', false, ErrorCategory::Validation, []],
            VaulterErrorCode::PermissionDenied => [403, 'Permission denied', false, ErrorCategory::Authorization, []],
            VaulterErrorCode::PreconditionRequired => [428, 'Precondition header required', false, ErrorCategory::Validation, ['header', 'reason']],
            VaulterErrorCode::RateLimited => [429, 'Rate limit exceeded', true, ErrorCategory::RateLimit, []],
            VaulterErrorCode::RegistryFrozen => [500, 'Extension registry frozen', false, ErrorCategory::Internal, []],
            VaulterErrorCode::ReplayedProblem => [409, 'Replayed idempotent problem', false, ErrorCategory::Conflict, []],
            VaulterErrorCode::RouteNotFound => [404, 'Route not found', false, ErrorCategory::NotFound, []],
            VaulterErrorCode::StaleWrite => [412, 'Stale write precondition failed', false, ErrorCategory::Validation, ['aggregate', 'id']],
            VaulterErrorCode::TenancyMisconfigured => [500, 'Tenancy misconfigured', false, ErrorCategory::Internal, []],
            VaulterErrorCode::UnknownIdStrategy => [500, 'Unknown ID strategy', false, ErrorCategory::Internal, []],
            VaulterErrorCode::UnknownOperation => [500, 'Unknown Vaulter operation', false, ErrorCategory::Internal, []],
            VaulterErrorCode::ValidationFailed => [422, 'Validation failed', false, ErrorCategory::Validation, []],
            VaulterErrorCode::WriteOutsideOperation => [500, 'Model write outside Vaulter operation', false, ErrorCategory::Internal, []],
        };
    }
}
