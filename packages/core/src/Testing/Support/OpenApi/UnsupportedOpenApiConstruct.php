<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support\OpenApi;

use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Thrown for an OpenAPI construct the test adapter cannot validate faithfully; the adapter
 * stops instead of skipping it.
 */
#[Internal]
final class UnsupportedOpenApiConstruct extends RuntimeException {}
