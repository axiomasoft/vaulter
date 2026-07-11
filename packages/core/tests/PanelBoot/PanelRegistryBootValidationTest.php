<?php

declare(strict_types=1);

use Vaulter\Exceptions\Panels\InvalidPanelException;
use Vaulter\Tests\BrokenPanelBootTestCase;

/*
 * F-P3.2-01 — a broken panel (here: a model FQCN that does not exist) now fails
 * loud DURING BOOT, not on the first live request that happens to touch it.
 * Red-before-fix (D7): before this item, PanelRegistry::class was a lazy
 * singleton nothing forced during packageBooted(), so BrokenPanelBootTestCase's
 * app would boot CLEANLY (no exception) and only throw once a test explicitly
 * resolved the panel — exactly the "config:cache is green, first request 500s"
 * defect the finding (P3.2-panel-seam.md) describes.
 */
it('surfaces InvalidPanelException at boot, not on first use, F-P3.2-01', function (): void {
    /** @var BrokenPanelBootTestCase $this */
    expect($this->bootException)->not->toBeNull()
        ->and($this->bootException)->toBeInstanceOf(InvalidPanelException::class)
        ->and($this->bootException->getMessage())->toContain('does not exist');
});
