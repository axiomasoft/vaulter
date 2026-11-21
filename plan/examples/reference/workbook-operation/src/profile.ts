/** Engine profile of the reference runtime. Target: packages/runtime/src/profile.ts. */
import {readFileSync, existsSync} from 'node:fs';
import {createRequire} from 'node:module';
import {dirname, join} from 'node:path';
import type {Limits, ProfileRef} from './wire.ts';

export const PROFILE: ProfileRef = {
    id: 'univer-oss-basic-1',
    engine: '@univerjs/core@1.5.2',
    plugins: [
        {name: '@univerjs/docs', version: '1.5.2'},
        {name: '@univerjs/engine-formula', version: '1.5.2'},
        {name: '@univerjs/engine-render', version: '1.5.2'},
        {name: '@univerjs/sheets', version: '1.5.2'},
        {name: '@univerjs/sheets-formula', version: '1.5.2'},
        {name: '@univerjs/sheets-numfmt', version: '1.5.2'},
    ],
};

/**
 * Plugin resources this composition writes and reads back (measured with `save()` on 1.5.2).
 * Anything else in `native.resources` (conditional formatting, data validation, drawings,
 * an unregistered plugin) would be dropped by `save()`, so it is refused before any write.
 */
export const PROFILE_RESOURCES: readonly string[] = [
    'SHEET_AuthzIoMockService_PLUGIN',
    'SHEET_DEFINED_NAME_PLUGIN',
    'SHEET_RANGE_PROTECTION_PLUGIN',
    'SHEET_RANGE_THEME_MODEL_PLUGIN',
    'SHEET_WORKSHEET_PROTECTION_PLUGIN',
    'SHEET_WORKSHEET_PROTECTION_POINT_PLUGIN',
];

export const SUPPORTED_COMMANDS = ['set_number', 'set_text', 'set_formula', 'set_values', 'insert_row_before', 'fill_formula'] as const;

export const DEFAULT_LIMITS: Limits = {max_commands: 64, max_cells_per_command: 10_000, calculation_timeout_ms: 20_000};

function installedVersion(name: string): string {
    const require = createRequire(import.meta.url);
    let directory = dirname(require.resolve(name));
    for (;;) {
        const candidate = join(directory, 'package.json');
        if (existsSync(candidate)) {
            const manifest = JSON.parse(readFileSync(candidate, 'utf8')) as {name?: string; version?: string};
            if (manifest.name === name && typeof manifest.version === 'string') return manifest.version;
        }
        const parent = dirname(directory);
        if (parent === directory) throw new Error(`package.json of ${name} not found`);
        directory = parent;
    }
}

/** Readiness check: the installed packages are exactly the pinned profile. */
export function assertInstalledProfile(profile: ProfileRef = PROFILE): void {
    const [engineName, engineVersion] = [profile.engine.slice(0, profile.engine.lastIndexOf('@')), profile.engine.slice(profile.engine.lastIndexOf('@') + 1)];
    for (const {name, version} of [{name: engineName, version: engineVersion}, ...profile.plugins]) {
        const actual = installedVersion(name);
        if (actual !== version) throw new Error(`profile ${profile.id} pins ${name}@${version}, installed ${actual}`);
    }
}
