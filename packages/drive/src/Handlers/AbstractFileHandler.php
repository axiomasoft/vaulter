<?php

declare(strict_types=1);

namespace Vaulter\Drive\Handlers;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Drive\Contracts\Handlers\FileHandler;

/**
 * Optional DX base for a third-party {@see FileHandler} (level 4,
 * `docs/extending/viewers.md`). Implementing FileHandler directly is always
 * enough — this only defaults the one method most handlers don't need to vary:
 * `extensions()`. `key()`, `mimeTypes()`, `priority()`, `capabilities()` and `viewer()`
 * stay handler-specific and are not touched here.
 *
 * The built-in handlers ({@see DownloadOnlyHandler}, {@see PreviewHandler}) do NOT
 * extend this — they stay `final` with zero inheritance (anti-self-deception:
 * `ArchTest` asserts the registry's own handlers are final). This base is for a
 * HOST's or PACKAGE's own handler, which is free to extend it or not.
 */
#[Spi(SpiKind::Implement)]
abstract class AbstractFileHandler implements FileHandler
{
    /**
     * No extension claims by default — a handler that routes purely on mime (e.g.
     * a synthetic content-type with no natural file extension) need not override
     * this.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return [];
    }
}
