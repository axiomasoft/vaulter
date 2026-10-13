<?php

declare(strict_types=1);

/*
 * Harness entry for the architecture inventory. The root autoloader does not know scripts/, so the library is
 * required by hand; composer.json is deliberately untouched.
 */

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/lib/Repo.php';
require_once __DIR__.'/lib/SourceIndex.php';
require_once __DIR__.'/lib/Allowlist.php';
require_once __DIR__.'/lib/Rules.php';
