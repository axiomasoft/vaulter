#!/usr/bin/env node
/**
 * CLI transport: exactly one UTF-8 JSON message + LF on stdin, one JSON message + LF on stdout.
 * Target: packages/runtime/src/cli.ts (bin `vaulter-sheets-runtime`). Logs go to stderr only.
 * Exit 0: a protocol response (ok true or false) was written. Exit 70: internal failure, no response.
 */
import {runOperation} from './operation.ts';
import {assertInstalledProfile} from './profile.ts';
import {WireError, parseStrict, type Json, type Response} from './wire.ts';

const MAX_MESSAGE_BYTES = 8 * 1024 * 1024;

async function readStdin(): Promise<Buffer> {
    const chunks: Buffer[] = [];
    let size = 0;
    for await (const chunk of process.stdin) {
        const buffer = chunk as Buffer;
        size += buffer.length;
        if (size > MAX_MESSAGE_BYTES + 1) throw new WireError('limit_exceeded', `message exceeds ${MAX_MESSAGE_BYTES} bytes`);
        chunks.push(buffer);
    }
    return Buffer.concat(chunks);
}

function requestIdOf(message: Json | undefined): string | null {
    if (message !== null && typeof message === 'object' && !Array.isArray(message) && typeof message.request_id === 'string') return message.request_id;
    return null;
}

async function main(): Promise<void> {
    assertInstalledProfile();
    let message: Json | undefined;
    let response: Response;
    try {
        const bytes = await readStdin();
        if (bytes.length === 0 || bytes[bytes.length - 1] !== 0x0a) throw new WireError('invalid_message', 'message must end with LF');
        const text = new TextDecoder('utf-8', {fatal: true}).decode(bytes.subarray(0, bytes.length - 1));
        message = parseStrict(text);
        response = await runOperation(message);
    } catch (error) {
        if (!(error instanceof WireError)) {
            if (error instanceof TypeError && /encoded data was not valid/.test(error.message)) {
                error = new WireError('invalid_message', 'message is not valid UTF-8');
            } else throw error;
        }
        const wire = error as WireError;
        response = {version: 1, request_id: requestIdOf(message), ok: false, error: {code: wire.code, message: wire.message, ...(wire.detail ? {detail: wire.detail} : {})}};
    }
    process.stdout.write(`${JSON.stringify(response)}\n`);
}

main().catch((error: unknown) => {
    process.stderr.write(`vaulter-sheets-runtime internal error: ${error instanceof Error ? error.stack ?? error.message : String(error)}\n`);
    process.exitCode = 70;
});
