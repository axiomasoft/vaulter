<?php

declare(strict_types=1);

/*
 * Vaulter package validation messages (vaulter:: namespace, P4.5 / F-P3.1-02).
 *
 * Kept separate from Laravel's own `validation.php` (a host app that ALSO
 * publishes/customises its root `lang/en/validation.php` never clobbers this
 * file, and vice versa) — resolved through the `vaulter::` package namespace,
 * registered via `Package::hasTranslations()` (spatie/laravel-package-tools
 * `ProcessTranslations` — `loadTranslationsFrom` off `resources/lang`).
 *
 * A host that wants a different language publishes this file
 * (`php artisan vendor:publish --tag=vaulter-translations`) into
 * `lang/vendor/vaulter/<locale>/validation.php` and translates it there —
 * Laravel's translation loader prefers the published copy over this default.
 */
return [

    // {@see \Vaulter\Support\DriveNameRules::messages()} — the shared node-name
    // validation message for create/rename/upload/copy Drive requests (§2.2).
    'name_no_separators' => 'The name must not contain path separators (/ or \\).',

];
