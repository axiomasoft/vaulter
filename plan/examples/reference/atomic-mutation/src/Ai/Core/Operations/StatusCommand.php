<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Operations;

use VaulterRef\Ai\Core\Contracts\CommandOwner;
use VaulterRef\Ai\Core\Contracts\ResourceDirectory;
use VaulterRef\Ai\Core\Data\CommandScope;
use VaulterRef\Ai\Core\Persistence\OwnerRoutes;
use VaulterRef\Ai\Core\Protocol\Wire;
use VaulterRef\Drive\DriveContext;

/**
 * Target: packages/core/src/Operations/Status.php in vaulter-ai. Never writes a domain outcome; it may only fill
 * the receipt locator of an outcome the owner already committed (CORE commit #3 of a dead process).
 */
final readonly class StatusCommand
{
    /** @param array<string, CommandOwner> $owners */
    public function __construct(private OwnerRoutes $routes, private ResourceDirectory $directory, private array $owners) {}

    /** @return array{http:int, body:array<string, mixed>} */
    public function handle(DriveContext $context, CommandScope $scope): array
    {
        $op = 'office.status';
        $route = $this->routes->find($scope);
        if ($route === null) {
            return Wire::error($op, $this->directory->visible($context, $scope->resourceId) ? 'operation_unknown' : 'resource_unavailable');
        }
        $owner = $this->owners[$route['owner_key']] ?? null;
        if ($owner === null) {
            return Wire::error($op, 'temporarily_unavailable');
        }
        $outcome = $owner->status($context, $scope, $route['fingerprint']);
        if ($outcome->state === 'committed' && $route['receipt_id'] !== $outcome->receiptId) {
            $this->routes->settle($scope, (string) $outcome->receiptId);
        }

        return Wire::outcome($op, $scope->commandId, $outcome);
    }
}
