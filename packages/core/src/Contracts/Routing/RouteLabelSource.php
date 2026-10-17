<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Routing;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * The metric labels of the routes a module owns. The kernel records `vaulter_http_request_duration_seconds` and
 * `vaulter_throttled_total` for every versioned route but knows no route of a module: each module contributes its
 * routes to the RouteLabelSource slot (ModuleBuilder::slot()), and the kernel merges the contributions in slot order,
 * the first label of a name winning.
 */
#[Spi(SpiKind::Implement)]
interface RouteLabelSource
{
    /** @return array<string, string> route name => the `operation` label of the request duration histogram */
    public function operations(): array;

    /**
     * Rules for collectors that see only a URI (php-fpm status, the load stand): the first rule that matches wins.
     * `{id}` stands for one path segment; the pattern is relative to the versioned prefix.
     *
     * @return list<array{methods: list<string>, pattern: string, operation: string}>
     */
    public function operationPaths(): array;

    /** @return array<string, string> route name => the logical `route` id of `vaulter_throttled_total` */
    public function throttled(): array;
}
