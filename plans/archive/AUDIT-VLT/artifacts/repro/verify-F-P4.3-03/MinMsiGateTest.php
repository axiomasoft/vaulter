<?php

declare(strict_types=1);

/**
 * Repro для F-P4.3-03 (скептик-верификация).
 *
 * Претензия: `infection.json5:9-10` (`minMsi: 0`, `minCoveredMsi: 0`) делает
 * мутационный гейт декоративным — Infection не проваливает прогон ни при
 * какой доле выживших мутантов.
 *
 * Тест не гоняет реальный (дорогой) мутационный прогон, а бьёт напрямую в
 * `Infection\Metrics\MinMsiChecker` — тот самый класс, который Infection
 * использует для решения «провалить CI или нет» (см.
 * vendor/infection/infection/src/Metrics/MinMsiChecker.php). Конструктор
 * принимает ровно те два значения, что лежат в infection.json5:9-10.
 *
 * Запуск:
 *   /usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/verify-F-P4.3-03/MinMsiGateTest.php
 */

use Infection\Metrics\MinMsiChecker;
use Infection\Metrics\MinMsiCheckFailed;
use Infection\Console\ConsoleOutput;

function checkerAsConfigured(): MinMsiChecker
{
    // Ровно значения из infection.json5:9-10.
    return new MinMsiChecker(
        ignoreMsiWithNoMutations: false,
        minMsi: 0.0,
        minCoveredCodeMsi: 0.0,
    );
}

it('гейт с minMsi=0/minCoveredMsi=0 из infection.json5 не бросает даже при MSI=0% и живых мутантах', function (): void {
    $checker = checkerAsConfigured();
    $output = Mockery::mock(ConsoleOutput::class)->shouldIgnoreMissing();

    // 100 мутантов, 0% MSI (все выжили), 0% coveredCodeMsi — худший возможный
    // результат прогона. Конфиг из репозитория обязан его пропустить молча.
    $checker->checkMetrics(
        totalMutantCount: 100,
        msi: 0.0,
        coveredCodeMsi: 0.0,
        consoleOutput: $output,
    );

    expect(true)->toBeTrue(); // дошли сюда без исключения — гейт действительно не сработал
});

it('тот же гейт с ненулевым порогом ДЕЙСТВИТЕЛЬНО проваливает прогон на MSI=0%', function (): void {
    // Контрольная проверка: класс умеет проваливать гейт — значит дело
    // именно в занулённых порогах конфига, а не в том, что MinMsiChecker
    // вообще ничего не проверяет.
    $checker = new MinMsiChecker(
        ignoreMsiWithNoMutations: false,
        minMsi: 50.0,
        minCoveredCodeMsi: 50.0,
    );
    $output = Mockery::mock(ConsoleOutput::class)->shouldIgnoreMissing();

    expect(fn () => $checker->checkMetrics(
        totalMutantCount: 100,
        msi: 0.0,
        coveredCodeMsi: 0.0,
        consoleOutput: $output,
    ))->toThrow(MinMsiCheckFailed::class);
});
