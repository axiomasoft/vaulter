<?php

declare(strict_types=1);

use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Kernel;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Webhooks\WebhooksProblemCodes;

/**
 * The problem codes of the webhooks module (moved from the kernel catalogue test with the module): the
 * lang files of the package stay in step with the code list, the codes appear in the catalogue under the module
 * only while it is active, and every code has an English and a Russian title and detail.
 */
it('keeps the lang files of the package in step with its code list', function (): void {
    $codes = array_keys((new WebhooksProblemCodes)->codes());
    sort($codes);

    foreach (['en', 'ru'] as $locale) {
        $lines = require dirname(__DIR__, 2)."/resources/lang/{$locale}/problems.php";
        $keys = array_keys($lines);
        sort($keys);

        expect($keys)->toBe($codes);
        foreach ($lines as $code => $line) {
            expect($line)->toHaveKeys(['title', 'detail'], $locale.' '.$code);
        }
    }
});

it('translates every code of the module in the language of the request', function (string $locale): void {
    $catalog = app(ProblemCatalog::class);

    foreach (array_keys((new WebhooksProblemCodes)->codes()) as $code) {
        expect($catalog->title($code, $locale))->toBeString()->not->toBe($code)
            ->and($catalog->detail($code, [], $locale))->toBeString();
    }
})->with(['en', 'ru']);

it('lists the codes under the webhooks module of the catalogue', function (): void {
    $catalog = app(ProblemCatalog::class)->all();

    expect($catalog['webhook_endpoint_not_found']['module'])->toBe('webhooks')
        ->and($catalog['webhook_endpoint_not_found']['status'])->toBe(404)
        ->and($catalog['webhook_delivery_not_redeliverable']['status'])->toBe(409)
        ->and($catalog['invalid_webhook_signature']['status'])->toBe(400);
});

it('keeps the codes out of the catalogue while the module is off', function (): void {
    $off = FreshApplication::boot(['vaulter.modules.webhooks' => false]);

    expect($off->make(Kernel::class)->isActive('webhooks'))->toBeFalse()
        ->and($off->make(ProblemCatalog::class)->all())->not->toHaveKey('webhook_endpoint_not_found');
});
