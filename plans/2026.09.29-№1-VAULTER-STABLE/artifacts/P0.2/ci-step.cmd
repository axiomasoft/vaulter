DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret scripts/php84 vendor/bin/pest --ci packages/core/tests/Concurrency
