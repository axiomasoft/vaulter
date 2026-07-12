<?php

declare(strict_types=1);

use Vaulter\Tests\P22Repro\TraceOrderBase;

require __DIR__.'/TraceOrderBase.php';

uses(TraceOrderBase::class);

it('traces vaulter.models keys at each registration checkpoint', function () {
    $models = $this->app['config']->get('vaulter.models');

    dump('trace = '.json_encode($GLOBALS['p22_trace'], JSON_PRETTY_PRINT));
    dump('final vaulter.models = '.json_encode($models, JSON_PRETTY_PRINT));

    expect(true)->toBeTrue();
});
