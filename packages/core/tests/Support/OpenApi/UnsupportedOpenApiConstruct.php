<?php

declare(strict_types=1);

namespace Vaulter\Tests\Support\OpenApi;

use RuntimeException;

/**
 * Thrown for an OpenAPI construct the test adapter cannot validate faithfully; the adapter
 * stops instead of skipping it.
 */
final class UnsupportedOpenApiConstruct extends RuntimeException {}
