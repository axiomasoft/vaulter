<?php

declare(strict_types=1);

use Vaulter\Tests\P22Repro\BaselineNoOverrideBase;

require __DIR__.'/BaselineNoOverrideBase.php';

uses(BaselineNoOverrideBase::class);

it('shows vaulter.models on a clean zero-config install (core+drive, nothing published)', function () {
    $models = $this->app['config']->get('vaulter.models');

    dump('Baseline vaulter.models = '.json_encode($models, JSON_PRETTY_PRINT));

    expect($models)->toHaveKey('upload_session');
});
