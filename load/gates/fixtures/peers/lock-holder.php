<?php
// Controlled peers only: no Laravel app, PDO connection, Docker or product writes.
namespace Peer {
    function log(string $event, array $fields = []): void {
        file_put_contents(getenv('P7_PEER_LOG'), json_encode(['event' => $event, ...$fields])."\n", FILE_APPEND);
    }
}
namespace Illuminate\Console {
    class Command {
        public const SUCCESS = 0;
        public const FAILURE = 1;
        public function argument($name) { return 'lock-holder'; }
        public function option($name) {
            global $argv;
            return match ($name) {
                'run-prefix' => $argv[2], 'bytes' => 16, 'ordinal' => 1,
                'hold-lock' => getenv('P7_PEER_HOLD') !== '0',
                'hold-timeout' => (int) (getenv('P7_PEER_TIMEOUT') ?: 2), default => null,
            };
        }
        public function line($text) { echo $text."\n"; }
    }
}
namespace Illuminate\Support\Facades {
    class DB {
        private static $queries = 0;
        public static function transaction($callback) {
            \Peer\log('transaction-open');
            try { $callback(); \Peer\log('commit'); }
            catch (\Throwable $error) { \Peer\log('rollback'); throw $error; }
        }
        public static function getDriverName() { return getenv('P7_PEER_DRIVER') ?: 'pgsql'; }
        public static function select($sql, $bindings) { \Peer\log('lock-acquired', ['key' => $bindings[0]]); }
        public static function selectOne($sql) {
            self::$queries++;
            if (self::$queries > 1 && file_exists(getenv('P7_PEER_FAILURE'))) {
                throw new \PDOException('Controlled connection loss');
            }
            return (object) ['backend_pid' => 123, 'database' => getenv('P7_PEER_DATABASE') ?: 'vaulter_load'];
        }
    }
}
namespace Vaulter\Enums\Webhooks { enum WebhookEvent { case NodeCreated; } }
namespace Vaulter\Media\Cas { class Sha256ContentStore {} }
namespace Vaulter\Webhooks {
    class OutboxRecorder {
        public function stage(...$args) { \Peer\log('outbox-stage', ['key' => $args['key']]); }
    }
}
namespace Vaulter\Load\Console {
    class ChaosFixtureSupport {
        public static function sanitizeToken($token) { return trim(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $token), '-') ?: 'default'; }
    }
}
namespace {
    require $argv[1];
    $command = new \Vaulter\Load\Console\ChaosOperationCommand();
    exit($command->handle(new \Vaulter\Media\Cas\Sha256ContentStore(), new \Vaulter\Webhooks\OutboxRecorder()));
}
