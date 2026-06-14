<?php

declare(strict_types=1);

use DocVault\Support\TemplateVariableSubstitutor;

it('substitutes known placeholders and leaves unknown ones', function (): void {
    $document = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello {{ name }}, welcome to {{ company }}.'],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Unknown {{ missing }} stays.'],
            ]],
        ],
    ];

    $result = (new TemplateVariableSubstitutor)->substitute($document, [
        'name' => 'Ada',
        'company' => 'Axioma',
    ]);

    expect($result['content'][0]['content'][0]['text'])->toBe('Hello Ada, welcome to Axioma.')
        ->and($result['content'][1]['content'][0]['text'])->toBe('Unknown {{ missing }} stays.');
});

it('leaves a document without placeholders untouched', function (): void {
    $document = ['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'Plain']]];

    expect((new TemplateVariableSubstitutor)->substitute($document, ['x' => 'y']))->toBe($document);
});
