<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support\OpenApi;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Env;
use Packman\Attributes\Internal;

/**
 * Switches the contract check on for a test case when VAULTER_OPENAPI_VALIDATE=1 (set in the
 * phpunit.xml of core, drive and documents), so every v1 feature test doubles as a contract test.
 */
#[Internal]
trait ValidatesOpenApiResponses
{
    protected function validateOpenApiResponses(mixed $app): void
    {
        if ((string) Env::get('VAULTER_OPENAPI_VALIDATE') !== '1') {
            return;
        }

        $app->make(Kernel::class)->pushMiddleware(ValidateOpenApiResponses::class);
    }
}
