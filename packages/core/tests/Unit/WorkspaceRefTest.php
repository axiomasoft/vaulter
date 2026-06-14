<?php

declare(strict_types=1);

use DocVault\ValueObjects\WorkspaceRef;

it('treats numeric and string ids as equal', function (): void {
    expect(WorkspaceRef::make('App\\Project', 5)->equals(new WorkspaceRef('App\\Project', '5')))->toBeTrue();
});

it('distinguishes different workspace types', function (): void {
    expect(WorkspaceRef::make('App\\Project', 1)->equals(WorkspaceRef::make('App\\Deal', 1)))->toBeFalse();
});
