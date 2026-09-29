"""Bounded stdin/stdout handshake with an already acquired product DB lock."""

import json
import os
import selectors
import subprocess
import time


class HolderError(RuntimeError):
    pass


class LockHolderSession:
    def __init__(self, fixture, *, database, launch=subprocess.Popen, timeout=10):
        self.fixture = fixture
        self.timeout = timeout
        self.ready = None
        self.process = launch([
            'docker', 'exec', '-i', 'vaulter-load-app-1', 'php', 'vendor/bin/testbench',
            'load:chaos-operation', 'lock-holder', f'--run-prefix={fixture["marker"]}',
            f'--bytes={fixture["requested_bytes"]}', '--ordinal=1', '--hold-lock',
            '--hold-timeout=240', '--no-ansi',
        ], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, bufsize=0)
        try:
            selector = selectors.DefaultSelector()
            selector.register(self.process.stdout, selectors.EVENT_READ)
            buffer = b''
            deadline = time.monotonic() + timeout
            with selector:
                while b'\n' not in buffer:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0 or not selector.select(remaining):
                        raise HolderError('lock-holder did not acknowledge its lock boundary')
                    chunk = os.read(self.process.stdout.fileno(), 4096)
                    if not chunk or len(buffer) + len(chunk) > 16384:
                        raise HolderError('lock-holder exited or returned an oversized boundary')
                    buffer += chunk
            line, trailing = buffer.split(b'\n', 1)
            if trailing:
                raise HolderError('lock-holder terminated before the fault boundary')
            ready = json.loads(line)
            expected = {'schema': 'p7-lock-holder-ready/v1', 'run_prefix': fixture['marker'],
                        'ordinal': 1, 'event_id': f'{fixture["marker"]}:lock-holder:1',
                        'database': database, 'lock_acquired': True}
            if not isinstance(ready, dict) or any(ready.get(key) != value for key, value in expected.items()):
                raise HolderError('lock-holder returned a foreign or unheld boundary')
            if (set(ready) != set(expected) | {'backend_pid'} or ready['lock_acquired'] is not True
                    or type(ready['ordinal']) is not int):
                raise HolderError('lock-holder returned a malformed boundary')
            if type(ready.get('backend_pid')) is not int or ready['backend_pid'] <= 0:
                raise HolderError('lock-holder omitted its DB backend identity')
            self.ready = ready
            self.ensure_held()
        except (ValueError, OSError, TypeError) as exc:
            self.close()
            raise HolderError('lock-holder boundary was invalid') from exc
        except BaseException:
            self.close()
            raise

    def ensure_held(self):
        if self.process.poll() is not None:
            raise HolderError('lock-holder exited before injection')

    def _control(self, action):
        message = {'schema': 'p7-lock-holder-control/v1', 'run_prefix': self.fixture['marker'],
                   'ordinal': 1, 'event_id': f'{self.fixture["marker"]}:lock-holder:1', 'action': action}
        self.process.stdin.write((json.dumps(message) + '\n').encode())
        self.process.stdin.flush()

    def finish(self):
        try:
            self._control('release')
            stdout, _ = self.process.communicate(timeout=self.timeout)
            measured = json.loads(stdout)
        except (ValueError, TypeError, OSError, subprocess.TimeoutExpired) as exc:
            raise HolderError('lock-holder did not return a terminal outcome') from exc
        finally:
            if self.process.poll() is None:
                self.close()
        expected = {'schema': 'p7-lock-holder-result/v1', 'operation': 'lock-holder',
                    'run_prefix': self.fixture['marker'], 'ordinal': 1, 'lock_boundary': self.ready}
        if (not isinstance(measured, dict) or 'error_class' not in measured
                or type(measured.get('ordinal')) is not int
                or any(measured.get(key) != value for key, value in expected.items())):
            raise HolderError('lock-holder terminal outcome has a foreign identity')
        if (type(measured.get('written_bytes')) is not int or measured['written_bytes'] < 0
                or type(measured.get('requested_bytes')) is not int
                or measured['requested_bytes'] != self.fixture['requested_bytes']):
            raise HolderError('lock-holder terminal outcome is unmeasured')
        error = measured.get('error_class')
        if error is not None and (not isinstance(error, str) or not error):
            raise HolderError('lock-holder terminal error is invalid')
        if (self.process.returncode == 0) != (measured.get('error_class') is None):
            raise HolderError('lock-holder exit and terminal outcome disagree')
        if self.process.returncode == 0 and (measured['written_bytes'] != measured['requested_bytes']
                                            or measured.get('entity_id') != self.ready['event_id']):
            raise HolderError('successful holder omitted its durable identity or measured mutation')
        return {'transport': 'artisan', 'operation': 'lock-holder', 'ordinal': 1,
                'request_id': 'lock-holder-1', 'status': 200 if self.process.returncode == 0 else 500,
                'command_exit': self.process.returncode, 'requested_bytes': measured['requested_bytes'],
                'written_bytes': measured['written_bytes'], 'error_class': measured['error_class'],
                'entity_id': measured.get('entity_id'), 'lock_boundary': self.ready}

    def close(self):
        try:
            if self.process.poll() is None:
                try:
                    self._control('cancel')
                except (OSError, ValueError):
                    pass
                try:
                    self.process.communicate(timeout=2)
                except subprocess.TimeoutExpired:
                    self.process.terminate()
                    try:
                        self.process.communicate(timeout=2)
                    except subprocess.TimeoutExpired:
                        self.process.kill()
                        self.process.communicate(timeout=2)
        finally:
            for stream in (self.process.stdin, self.process.stdout):
                if stream is not None:
                    stream.close()
