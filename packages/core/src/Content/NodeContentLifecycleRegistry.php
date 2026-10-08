<?php

declare(strict_types=1);

namespace Vaulter\Content;

use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Contracts\Drive\PurgeOnlyContentLifecycle;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Exceptions\Registry\DuplicateNodeContentLifecycleException;

final class NodeContentLifecycleRegistry
{
    /** @var array<string, NodeContentLifecycle> */
    private array $handlers = [];

    /** @var array<string, NodeContentLifecycle> */
    private array $fallbacks = [];

    public function register(NodeContentLifecycle $handler): void
    {
        $key = $handler->kind()->value;
        if (isset($this->handlers[$key])) {
            throw DuplicateNodeContentLifecycleException::forKind($handler->kind());
        }
        $this->handlers[$key] = $handler;
    }

    /**
     * A lifecycle used only while no package registers one for the kind: drive keeps
     * purging documents when the documents package is not installed.
     */
    public function registerFallback(NodeContentLifecycle $handler): void
    {
        $key = $handler->kind()->value;
        if (isset($this->fallbacks[$key])) {
            throw DuplicateNodeContentLifecycleException::forKind($handler->kind());
        }
        $this->fallbacks[$key] = $handler;
    }

    public function forKind(NodeKind $kind): NodeContentLifecycle
    {
        return $this->handlers[$kind->value]
            ?? $this->fallbacks[$kind->value]
            ?? throw UnsupportedNodeKindException::forKind($kind);
    }

    public function assertCopySupported(NodeKind $kind): void
    {
        if ($kind === NodeKind::Folder) {
            return;
        }
        if ($this->forKind($kind) instanceof PurgeOnlyContentLifecycle) {
            throw UnsupportedNodeKindException::forKind($kind);
        }
    }
}
