<?php
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql' || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '5432' || getenv('DB_DATABASE') !== 'vaulter_test' || !preg_match('/^[a-z0-9_]+_test$/',getenv('DB_DATABASE'))) throw new RuntimeException('PG target not exact isolated test DB');
$pdo = new PDO('pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$row=$pdo->query('SELECT current_database() AS database,current_user AS username,inet_server_addr() AS address,inet_server_port() AS port,version() AS version')->fetch(PDO::FETCH_ASSOC);
if ($row['database'] !== 'vaulter_test' || !str_ends_with($row['database'],'_test')) throw new RuntimeException('Connected wrong DB');
if ($row['username'] !== 'vaulter' || $row['address'] !== '127.0.0.1' || (int) $row['port'] !== 5432 || !str_contains($row['version'], 'PostgreSQL 16.')) throw new RuntimeException('Unexpected PostgreSQL runtime/endpoint');
echo json_encode($row), PHP_EOL;
echo 'APP_ENV=testing; host-mode exact vaulter_test proven; core/drive DatabaseMigrations/RefreshDatabase cover migration lifecycle; Upgrade fixtures own schema lifecycle', PHP_EOL;
