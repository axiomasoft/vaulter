<?php
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) throw new RuntimeException('Expected PHP 8.4');
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') throw new RuntimeException('SQLite env not isolated');
foreach (['pdo_sqlite','sqlite3','pcntl','posix','fileinfo'] as $ext) if (!extension_loaded($ext)) throw new RuntimeException('Missing '.$ext);
foreach (['packages/media/phpunit.xml','packages/azgard/phpunit.xml','phpunit.xml'] as $xml) {
 $cfg=simplexml_load_file($xml); $names=[]; foreach ($cfg->php->env as $v) $names[(string)$v['name']]=(string)$v['value'];
 if ($names['APP_ENV']!=='testing' || $names['DB_CONNECTION']!=='sqlite' || $names['DB_DATABASE']!==':memory:') throw new RuntimeException('Unsafe '.$xml);
 echo $xml, ': isolated SQLite memory test config',PHP_EOL;
}
echo json_encode(['php'=>PHP_VERSION,'binary'=>PHP_BINARY,'APP_ENV'=>getenv('APP_ENV'),'DB_CONNECTION'=>getenv('DB_CONNECTION'),'DB_DATABASE'=>getenv('DB_DATABASE'),'sqlite_select'=>(new PDO('sqlite::memory:'))->query('SELECT 1')->fetchColumn()]),PHP_EOL;
