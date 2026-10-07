<?php

declare(strict_types=1);

namespace Vaulter\Content;

use LogicException;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;

final class NodeContentLifecycleRegistry
{
    /** @var array<string, NodeContentLifecycle> */
    private array $handlers = [];

    public function register(NodeContentLifecycle $handler): void
    {
        $key = $handler->kind()->value;
        if (isset($this->handlers[$key])) {
            throw new LogicException('Duplicate node content lifecycle: '.$key.'.');
        }
        $this->handlers[$key] = $handler;
    }

    public function forKind(NodeKind $kind): NodeContentLifecycle
    {
        return $this->handlers[$kind->value] ?? throw UnsupportedNodeKindException::forKind($kind);
    }

    public function assertCopySupported(NodeKind $kind): void
    {
        if ($kind === NodeKind::Folder) {
            return;
        }
        // These registered handlers deliberately support purge only until P4.2.
        if ($kind === NodeKind::Document || $kind === NodeKind::Shortcut) {
            throw UnsupportedNodeKindException::forKind($kind);
        }
        $this->forKind($kind);
    }
}
