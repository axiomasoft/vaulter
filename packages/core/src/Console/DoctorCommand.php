<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Doctor\DoctorReport;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;

/**
 * Read-only diagnosis of the host's Vaulter setup. It never repairs anything; a finding's
 * hint names the command that does.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'vaulter:doctor
        {--production : Check against the production profile: part of the warnings become errors}
        {--json : Print one vaulter.doctor/v1 JSON document}
        {--check=* : Run only the checks under this key prefix, e.g. core.schema or drive.scan}
        {--sample=1000 : Row limit of the sampling checks}
        {--probe-storage : Allow writing a test object to each storage disk}';

    protected $description = 'Diagnose the Vaulter setup of this host (read-only)';

    public function handle(DoctorRunner $runner, DoctorRegistry $registry): int
    {
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT);
        if ($sample === false || $sample < 1) {
            return $this->usage('--sample must be a positive integer.');
        }
        $prefixes = array_values(array_filter(array_map(strval(...), (array) $this->option('check')), static fn (string $prefix): bool => $prefix !== ''));
        $unknown = $registry->unmatched($prefixes);
        if ($unknown !== []) {
            return $this->usage('No doctor check matches --check='.implode(', ', $unknown).'.');
        }

        $report = $runner->run(
            context: new DoctorContext(
                production: $this->option('production') === true,
                probeStorage: $this->option('probe-storage') === true,
                sampleLimit: $sample,
                now: CarbonImmutable::now('UTC'),
            ),
            prefixes: $prefixes,
            exposeMessages: $this->output->isVerbose(),
        );

        if ($this->option('json') === true) {
            $this->output->writeln(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        } else {
            $this->render($report);
        }

        return $report->exitCode();
    }

    private function usage(string $message): int
    {
        // Stdout of --json carries the document only.
        $this->output->getErrorStyle()->writeln('<error>'.OutputFormatter::escape($message).'</error>');

        return 2;
    }

    private function render(DoctorReport $report): void
    {
        foreach ($report->checks as $check) {
            $this->line('<options=bold>'.OutputFormatter::escape($check->title).'</> <fg=gray>'.$check->key.'</>');
            if ($check->detail !== null) {
                $this->line('  <fg=gray>detail: '.OutputFormatter::escape($check->detail).'</>');
            }
            foreach ($check->findings as $finding) {
                [$mark, $color] = match ($finding->severity) {
                    DoctorSeverity::Ok => ['ok  ', 'green'],
                    DoctorSeverity::Warning => ['warn', 'yellow'],
                    DoctorSeverity::Error => ['FAIL', 'red'],
                };
                $this->line('  <fg='.$color.'>'.$mark.'</> '.OutputFormatter::escape($finding->message).' <fg=gray>['.$finding->code.']</>');
                if ($finding->hint !== null) {
                    $this->line('       <fg=gray>hint: '.OutputFormatter::escape($finding->hint).'</>');
                }
            }
        }
        $summary = $report->summary();
        $this->newLine();
        $this->line(sprintf('%d ok, %d warning, %d error', $summary['ok'], $summary['warning'], $summary['error']));
    }
}
