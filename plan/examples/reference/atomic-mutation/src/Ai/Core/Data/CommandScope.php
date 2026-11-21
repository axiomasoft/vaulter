<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Data;

use InvalidArgumentException;
use VaulterRef\Drive\DriveContext;

/**
 * Target: packages/core/src/Data/CommandScope.php in vaulter-ai. The logical key of an office command:
 * (tenant_id, project_scope, effective_subject, resource_id, command_id). Tenant, project and subject come from
 * the trusted context; only resource_id and command_id come from the request.
 */
final readonly class CommandScope
{
    public function __construct(
        public string $tenantId,
        public string $projectScope,
        public string $effectiveSubject,
        public string $resourceId,
        public string $commandId,
    ) {
        foreach ([$tenantId, $projectScope, $effectiveSubject, $resourceId, $commandId] as $part) {
            if (strlen($part) < 1 || strlen($part) > 255) {
                throw new InvalidArgumentException('Every key component is 1..255 bytes.');
            }
        }
    }

    public static function of(DriveContext $context, string $projectScope, string $resourceId, string $commandId): self
    {
        return new self((string) $context->tenantId, $projectScope, $context->subjectRef(), $resourceId, $commandId);
    }

    /** @return array{tenant:string, project:string, subject:string, resource:string, command:string} */
    public function params(): array
    {
        return ['tenant' => $this->tenantId, 'project' => $this->projectScope, 'subject' => $this->effectiveSubject,
            'resource' => $this->resourceId, 'command' => $this->commandId];
    }
}
