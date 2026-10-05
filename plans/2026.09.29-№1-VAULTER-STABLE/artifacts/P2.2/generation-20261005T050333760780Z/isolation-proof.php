<?php
declare(strict_types=1);
$root = $argv[1];
require $root.'/vendor/autoload.php';
if (!class_exists(Vaulter\Drive\Tests\TestCase::class)) {
    require $root.'/packages/drive/tests/TestCase.php';
}
$core = new class('proof') extends Vaulter\Tests\TestCase {
    public function apply($app): void { $this->defineEnvironment($app); }
};
$drive = new class('proof') extends Vaulter\Drive\Tests\TestCase {
    public function apply($app): void { $this->defineEnvironment($app); }
};
$result = ['php_version' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'environment' => ['APP_ENV' => getenv('APP_ENV'), 'DB_CONNECTION' => getenv('DB_CONNECTION'), 'DB_DATABASE' => getenv('DB_DATABASE')], 'packages' => []];
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
    throw new RuntimeException('Explicit isolated test environment was not selected');
}
foreach (['core' => $core, 'drive' => $drive] as $name => $case) {
    $app = new Illuminate\Container\Container;
    $app->instance('config', new Illuminate\Config\Repository);
    $case->apply($app);
    $config = $app['config'];
    $default = $config->get('database.default');
    $connection = $config->get('database.connections.'.$default);
    if ($connection['driver'] !== 'sqlite' || $connection['database'] !== ':memory:') {
        throw new RuntimeException($name.' selected a non-memory connection');
    }
    $pdo = new PDO('sqlite:'.$connection['database']);
    $databases = $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
    if (count($databases) !== 1 || $databases[0]['file'] !== '') {
        throw new RuntimeException($name.' SQLite connected to a filesystem database');
    }
    $result['packages'][$name] = ['resolved_default' => $default, 'resolved_connection' => $connection, 'live_database_list' => $databases, 'scope' => 'actual TestCase defineEnvironment resolved through Illuminate config; PDO read-only probe; snapshot Arch tests do not boot TestCase or migrate'];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
