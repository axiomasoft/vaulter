<?php

declare(strict_types=1);

namespace Vaulter\Engines\Handlers;

use Vaulter\Contracts\Handlers\FileHandler;

/**
 * Optional DX base for a third-party {@see FileHandler} (P0.5 / §7 level 4,
 * `docs/guide/writing-an-engine.md`). Implementing FileHandler directly is always
 * enough — this only defaults the one method most handlers don't need to vary:
 * `extensions()`. `id()`, `mimeTypes()`, `priority()`, `capabilities()` and `ui()`
 * stay handler-specific and are not touched here.
 *
 * The built-in handlers ({@see DownloadOnlyHandler}, {@see PreviewHandler}) do NOT
 * extend this — they stay `final` with zero inheritance (H.5 anti-self-deception:
 * `ArchTest` asserts the registry's own handlers are final). This base is for a
 * HOST's or PACKAGE's own handler, which is free to extend it or not.
 */
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
