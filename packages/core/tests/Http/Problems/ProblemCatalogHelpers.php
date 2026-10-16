<?php

declare(strict_types=1);

use Vaulter\Documents\Http\Problems\DocumentsProblemCodes;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Http\Problems\CoreProblemCodes;
use Vaulter\Http\Problems\DriveSliceProblemCodes;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Media\Http\Problems\MediaProblemCodes;
use Vaulter\Webhooks\WebhooksProblemCodes;

// Shared by ProblemCatalogLangTest, ProblemSpecRulesTest and ErrorsReferenceTest. It lives in a file of its own, loaded
// by each, so that a test file run in a ParaTest worker without its siblings still finds it.

/**
 * The problem sources of the whole monorepo by declaring module (D39: the drive and webhooks codes located in
 * core are declared by their owners).
 *
 * @return array<string, list<class-string<ProblemCodeSource>>>
 */
function monorepoProblemSources(): array
{
    return [
        'core' => [CoreProblemCodes::class],
        'media' => [MediaProblemCodes::class],
        'drive' => [DriveSliceProblemCodes::class, DriveProblemCodes::class],
        'webhooks' => [WebhooksProblemCodes::class],
        'documents' => [DocumentsProblemCodes::class],
    ];
}

/**
 * The catalogue of the whole monorepo, every module active, with the lang namespaces added by path so the
 * check does not depend on which providers this suite boots.
 */
function fullProblemCatalog(): ProblemCatalog
{
    $root = dirname(__DIR__, 5).'/packages';
    $translator = app('translator');
    $translator->addNamespace('vaulter', $root.'/core/resources/lang');
    $translator->addNamespace('vaulter-media', $root.'/media/resources/lang');
    $translator->addNamespace('vaulter-drive', $root.'/drive/resources/lang');
    $translator->addNamespace('vaulter-documents', $root.'/documents/resources/lang');

    return new ProblemCatalog(
        static fn (): array => array_map(
            static fn (array $classes): array => array_map(static fn (string $class): ProblemCodeSource => new $class, $classes),
            monorepoProblemSources(),
        ),
        $translator,
    );
}
