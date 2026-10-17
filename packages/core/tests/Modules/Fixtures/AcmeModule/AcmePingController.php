<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use Illuminate\Http\JsonResponse;

final readonly class AcmePingController
{
    public function __construct(private AcmeService $service)
    {
        AcmeProbe::resolved(self::class);
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['greeting' => $this->service->greeting()]);
    }
}
