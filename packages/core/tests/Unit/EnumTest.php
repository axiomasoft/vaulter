<?php

declare(strict_types=1);

use Vaulter\Collaboration\Enums\CollaborationDriverType;

it('labels collaboration drivers', function (): void {
    expect(CollaborationDriverType::Hocuspocus->label())->toBe('Hocuspocus')
        ->and(CollaborationDriverType::cases())->toBe([CollaborationDriverType::Hocuspocus]);
});
