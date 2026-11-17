<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Http\UploadedFile;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Http\Requests\Files\UploadFileRequest;

it('accepts a nonempty ordinary upload when the canonical byte limit is unlimited', function (): void {
    $options = DriveOptions::fromArray(['uploads' => ['max_file_size' => 0]]);
    $rules = (new UploadFileRequest)->rules($options);
    $file = UploadedFile::fake()->createWithContent(name: 'payload.txt', content: str_repeat('x', 4096));

    $validator = app(Factory::class)->make(data: ['file' => $file], rules: $rules);

    expect($validator->passes())->toBeTrue();
});

it('enforces positive canonical byte limits without rounding up to a whole kilobyte', function (int $limit): void {
    $options = DriveOptions::fromArray(['uploads' => ['max_file_size' => $limit]]);
    $rules = (new UploadFileRequest)->rules($options);
    $factory = app(Factory::class);
    $atLimit = UploadedFile::fake()->createWithContent(name: 'allowed.txt', content: str_repeat('x', $limit));
    $overLimit = UploadedFile::fake()->createWithContent(name: 'rejected.txt', content: str_repeat('x', $limit + 1));

    expect($factory->make(data: ['file' => $atLimit], rules: $rules)->passes())->toBeTrue()
        ->and($factory->make(data: ['file' => $overLimit], rules: $rules)->errors()->has('file'))->toBeTrue();
})->with([1, 1025]);
