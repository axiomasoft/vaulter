<?php

declare(strict_types=1);

use Vaulter\Documents\Http\Problems\DocumentsProblemCodes;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Http\Problems\CoreProblemCodes;
use Vaulter\Http\Problems\ProblemCatalog;

// Shared by ProblemCatalogLangTest and ErrorsReferenceTest. It lives in a file of its own, loaded by both, so that a test
// file run in a ParaTest worker without its sibling still finds it.
/**
 * The catalogue of the whole monorepo: core plus the drive and documents sources, with
 * their lang namespaces added by path so the check does not depend on which providers
 * this suite boots.
 */
function fullProblemCatalog(): ProblemCatalog
{
    $root = dirname(__DIR__, 5).'/packages';
    $translator = app('translator');
    $translator->addNamespace('vaulter', $root.'/core/resources/lang');
    $translator->addNamespace('vaulter-drive', $root.'/drive/resources/lang');
    $translator->addNamespace('vaulter-documents', $root.'/documents/resources/lang');

    return new ProblemCatalog(
        static fn (): array => [new CoreProblemCodes, new DriveProblemCodes, new DocumentsProblemCodes],
        $translator,
    );
}
