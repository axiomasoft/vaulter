<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

/**
 * The npm adapter: compares the declaration reports of two revisions — the API
 * Extractor `etc/index.api.md` per exported entrypoint and the `etc/components.surface.txt` snapshot of every exported .vue
 * component's own `$props` and `$emit` (slot components are rolled up by API Extractor, so their props and events are not in
 * the .api.md). A removed export, prop or event is a BREAK; a prop turned required, a new required prop, a changed type or
 * event signature or a changed export declaration is REVIEW (variance is not classified); a new export, optional prop or
 * event is ADD. A green adapter proves the declaration signature guard only — no runtime, packed-bytes or Vue behavior.
 *
 * @phpstan-import-type ChangeRow from Change
 */
final class NpmSurface
{
    private const EXPORT = '/^export (?:declare )?(?:default )?(?:abstract )?(const|function|class|interface|type|enum|namespace)\s+([A-Za-z_$][\w$]*)/m';

    /**
     * @return array{api: array<string, string>, props: array<string, array{optional: bool, type: string}>, emits: array<string, string>}
     */
    public static function load(string $report, string $components, string $path): array
    {
        if (!str_starts_with($report, '## API Report File for ')) {
            throw new ContractError("{$path}: not an API Extractor report");
        }
        $api = [];
        foreach (preg_split('/\n{2,}/', $report) ?: [] as $block) {
            if (preg_match(self::EXPORT, $block, $m) === 1) {
                $key = "{$m[1]}:{$m[2]}";
                $api[$key] = ($api[$key] ?? '').$block."\n";
            }
        }
        if ($api === []) {
            throw new ContractError("{$path}: the report declares no export");
        }
        $props = [];
        $emits = [];
        foreach (explode("\n", rtrim($components, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\S+) prop ([\w$-]+)(\?)?: (.+)$/', $line, $m) === 1) {
                $props["{$m[1]}.{$m[2]}"] = ['optional' => $m[3] === '?', 'type' => preg_replace('/ \| undefined$/', '', $m[4]) ?? $m[4]];
            } elseif (preg_match('/^(\S+) emit \(event: (?:"([^"]+)"|string)/', $line, $m) === 1) {
                $emits["{$m[1]}@".($m[2] ?? '*')] = $line; // an untyped $emit(event: string, ...args) is one `*` row
            } else {
                throw new ContractError("{$path}: unreadable component surface row '{$line}'");
            }
        }

        return ['api' => $api, 'props' => $props, 'emits' => $emits];
    }

    /**
     * @param  array{api: array<string, string>, props: array<string, array{optional: bool, type: string}>, emits: array<string, string>} $before
     * @param  array{api: array<string, string>, props: array<string, array{optional: bool, type: string}>, emits: array<string, string>} $after
     * @return list<ChangeRow>
     */
    public static function compare(string $surface, array $before, array $after): array
    {
        $changes = [];
        $row = static function (string $kind, string $key, string $rule, string $class, mixed $b, mixed $a, string $why) use (&$changes, $surface): void {
            $changes[] = Change::make($surface, "{$kind}:{$key}", $rule, $class, $b, $a, $why);
        };
        foreach (array_diff_key($before['api'], $after['api']) as $key => $b) {
            $row('export', $key, 'export-removed', Change::BREAK, $b, null, 'a published export is gone');
        }
        foreach (array_diff_key($after['api'], $before['api']) as $key => $a) {
            $row('export', $key, 'export-added', Change::ADD, null, $a, 'a new export');
        }
        foreach (array_intersect_key($after['api'], $before['api']) as $key => $a) {
            if ($a !== $before['api'][$key]) {
                $row('export', $key, 'export-changed', Change::REVIEW, $before['api'][$key], $a, 'the declaration of an export changed; variance is not classified');
            }
        }
        foreach (array_diff_key($before['props'], $after['props']) as $key => $b) {
            $row('prop', $key, 'prop-removed', Change::BREAK, $b, null, 'a component prop is gone');
        }
        foreach (array_diff_key($after['props'], $before['props']) as $key => $a) {
            $a['optional']
                ? $row('prop', $key, 'prop-added', Change::ADD, null, $a, 'a new optional prop')
                : $row('prop', $key, 'prop-added-required', Change::REVIEW, null, $a, 'a new required prop breaks callers that do not pass it');
        }
        foreach (array_intersect_key($after['props'], $before['props']) as $key => $a) {
            $b = $before['props'][$key];
            if ($b['optional'] && !$a['optional']) {
                $row('prop', $key, 'prop-required', Change::REVIEW, $b, $a, 'an optional prop became required');
            } elseif ($b !== $a) {
                $row('prop', $key, 'prop-changed', Change::REVIEW, $b, $a, 'the type of a prop changed');
            }
        }
        foreach (array_diff_key($before['emits'], $after['emits']) as $key => $b) {
            $row('emit', $key, 'event-removed', Change::BREAK, $b, null, 'a component event is gone');
        }
        foreach (array_diff_key($after['emits'], $before['emits']) as $key => $a) {
            $row('emit', $key, 'event-added', Change::ADD, null, $a, 'a new event');
        }
        foreach (array_intersect_key($after['emits'], $before['emits']) as $key => $a) {
            if ($a !== $before['emits'][$key]) {
                $row('emit', $key, 'event-changed', Change::REVIEW, $before['emits'][$key], $a, 'the signature of an event changed');
            }
        }

        return Change::sorted($changes);
    }
}
