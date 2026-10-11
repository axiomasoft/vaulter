<?php

declare(strict_types=1);

namespace Vaulter\Tests\Support\OpenApi;

use Illuminate\Contracts\Http\Kernel;

/**
 * Switches the contract check on for a test case when VAULTER_OPENAPI_VALIDATE=1 (set in the
 * phpunit.xml of core, drive and documents), so every v1 feature test doubles as a contract test.
 */
trait ValidatesOpenApiResponses
{
    protected function validateOpenApiResponses(mixed $app): void
    {
        if ((string) env('VAULTER_OPENAPI_VALIDATE') !== '1') {
            return;
        }

        $app->make(Kernel::class)->pushMiddleware(ValidateOpenApiResponses::class);
    }
}
