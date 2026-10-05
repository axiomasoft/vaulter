<?php
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) throw new RuntimeException('Expected PHP 8.4');
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') throw new RuntimeException('SQLite env not isolated');
foreach (['pdo', 'pdo_sqlite', 'sqlite3', 'pdo_pgsql', 'pcntl', 'posix', 'fileinfo'] as $ext) if (!extension_loaded($ext)) throw new RuntimeException('Missing '.$ext);
$db = new PDO('sqlite::memory:');
echo json_encode(['php'=>PHP_VERSION,'binary'=>PHP_BINARY,'APP_ENV'=>getenv('APP_ENV'),'db'=>'sqlite::memory:','pdo_drivers'=>PDO::getAvailableDrivers(),'sqlite_select'=>$db->query('SELECT 1')->fetchColumn()]), PHP_EOL;
foreach (['phpunit.xml','packages/drive/phpunit.xml','packages/documents/phpunit.xml','packages/filament/phpunit.xml'] as $xml) {
    $cfg = simplexml_load_file($xml);
    $names = [];
    foreach ($cfg->php->env as $v) $names[(string)$v['name']] = (string)$v['value'];
    if ($names['APP_ENV'] !== 'testing' || $names['DB_CONNECTION'] !== 'sqlite' || $names['DB_DATABASE'] !== ':memory:') throw new RuntimeException('Unsafe '.$xml);
    echo $xml, ': APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:', PHP_EOL;
}
foreach (['packages/core/tests/TestCase.php','packages/drive/tests/TestCase.php','packages/documents/tests/TestCase.php','packages/filament/tests/TestCase.php'] as $file) {
    if (!str_contains(file_get_contents($file), "'database' => ':memory:'")) throw new RuntimeException('No SQLite memory fixture: '.$file);
    echo 'fixture ', $file, ' SHA256=', hash_file('sha256', $file), PHP_EOL;
}
foreach (['packages/drive/tests/Drive/UploadFileTest.php','packages/drive/tests/Concurrency/QuotaRaceTest.php','packages/drive/tests/Uploads/TusFlowTest.php'] as $file) {
    if (!str_contains(file_get_contents($file), "Storage::fake('local')")) throw new RuntimeException('No disk fake: '.$file);
    echo 'existing disk fake ', $file, PHP_EOL;
}

$inventory=json_decode(file_get_contents(__DIR__.'/external-tools.json'), true);
foreach ($inventory as $path => $sha) if (hash_file('sha256', $path) !== $sha) throw new RuntimeException('External tool/runtime drift '.$path);
echo 'external tools/runtime inventory verified; PHP binary sha256=', hash_file('sha256', PHP_BINARY), PHP_EOL;
