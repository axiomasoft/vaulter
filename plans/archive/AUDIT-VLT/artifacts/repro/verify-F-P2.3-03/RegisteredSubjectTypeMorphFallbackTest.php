<?php

declare(strict_types=1);

/**
 * Repro для скепсис-ревью F-P2.3-03 ("Morph map не зарегистрирован — 5 колонок
 * хранят сырой FQCN").
 *
 * Тезис находки: пакет ДОЛЖЕН сам регистрировать Relation::morphMap для полиморфных
 * колонок owner_type/subject_type/author_type/actor_type/target_type, иначе туда
 * пишется сырой FQCN — трактуется как дефект (Major).
 *
 * Опровержение: для 4 из 5 колонок (owner_type/subject_type/author_type/actor_type)
 * цель морфа — МОДЕЛЬ ХОСТА (User/Role/Team), которую пакет не знает и не может
 * заранее сматчить в свой config('vaulter.models.*') (там только СОБСТВЕННЫЕ модели
 * Vaulter). RegisteredSubjectType (packages/core/src/Rules/Access/RegisteredSubjectType.php)
 * осознанно ветвится: alias, если host зарегистрировал Relation::morphMap; иначе —
 * FQCN разрешён (нативный Laravel-фоллбэк), И ТОЛЬКО если host НЕ включил
 * enforceMorphMap(). Если host включил enforceMorphMap() — сырой FQCN отклоняется
 * ещё на входе (валидацией), то есть до колонки он просто не доходит.
 *
 * Тест ниже воспроизводит оба ветвления на самом правиле — без изменения
 * production-кода — и показывает, что поведение УПРАВЛЯЕМО и документировано
 * (docs/guide/troubleshooting.md #azgard-morphs, docs/guide/grants.md), а не
 * является забытой регистрацией.
 */

use Illuminate\Database\Eloquent\Relations\Relation;
use Vaulter\Models\Drive\Node;
use Vaulter\Rules\Access\RegisteredSubjectType;

uses(Vaulter\Tests\TestCase::class);

afterEach(function (): void {
    // Не протечь enforceMorphMap()/morphMap() в соседние тесты сьюта.
    Relation::morphMap([], merge: false);
    Relation::requireMorphMap(false);
});

it('без enforceMorphMap хост-FQCN Eloquent-модели проходит валидацию — это нативный Laravel-фоллбэк, а не дыра пакета', function (): void {
    $rule = new RegisteredSubjectType();

    // Node — реальная Eloquent-модель (стенд-ин за "хостовую" модель типа User,
    // которую пакет заведомо не знает и не может заранее прописать в свой
    // config('vaulter.models.*')). Хост НЕ включал enforceMorphMap().
    $failed = false;
    $rule->validate('subject_type', Node::class, function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});

it('с enforceMorphMap() сырой FQCN отклоняется ДО записи в колонку — путь закрыт на входе', function (): void {
    Relation::enforceMorphMap([
        'user' => Node::class,
    ]);

    $rule = new RegisteredSubjectType();

    $failed = false;
    $rule->validate('subject_type', Node::class, function () use (&$failed): void {
        $failed = true;
    });

    // Хост включил enforceMorphMap — сырой FQCN (не входящий в алиасы) отсеивается
    // валидацией, а не молча оседает в v_node_grants.subject_type.
    expect($failed)->toBeTrue();

    $failedAlias = false;
    $rule->validate('subject_type', 'user', function () use (&$failedAlias): void {
        $failedAlias = true;
    });

    expect($failedAlias)->toBeFalse();
});
