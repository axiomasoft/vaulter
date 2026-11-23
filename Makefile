.PHONY: help db-up db-down db-up-mysql db-down-mysql test-changed test-db test-db-mysql test-full lint lint-check stan rector rector-fix quality quality-fix test test-coverage ci

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

lint: ## Fix code style with Pint
	vendor/bin/pint

lint-check: ## Check code style (no fix)
	vendor/bin/pint --test

stan: ## Run PHPStan static analysis
	vendor/bin/phpstan analyse --no-progress --memory-limit=512M

rector: ## Run Rector dry-run (no changes)
	vendor/bin/rector --dry-run --no-progress-bar

rector-fix: ## Apply Rector refactoring
	vendor/bin/rector --no-progress-bar

quality: lint-check stan rector ## Run all quality checks (no fix)

quality-fix: lint rector-fix ## Fix code style and apply Rector

test: ## Run every default suite on SQLite through scripts/t (fast lane, no coverage)
	scripts/t sqlite

db-up: ## Start the reusable local pgsql test server in /dev/shm (saves ~5 s per scripts/t pgsql run)
	scripts/pg-local.sh up

db-down: ## Stop the local pgsql test server
	scripts/pg-local.sh down

db-up-mysql: ## Start the reusable local mysql test server (Docker, data on tmpfs; core DatabaseMigrations 702 s -> 158 s)
	scripts/mysql-local.sh up

db-down-mysql: ## Stop the local mysql test server and drop its data
	scripts/mysql-local.sh down

test-changed: ## Local lane: affected zones, SQLite + pgsql database tests (see tests/README.md Run policy)
	scripts/t --changed --db=pgsql

test-db: db-up ## Every engine-dependent test on pgsql (the database group of every suite)
	scripts/t pgsql --group=database

test-db-mysql: db-up-mysql ## Every engine-dependent test on mysql
	scripts/t mysql --group=database

test-full: ## The whole matrix (sqlite+pgsql+mysql, --full), every engine/package cell on its own private server in parallel: before closing a plan / a release only
	scripts/t-matrix

test-coverage: ## Core suite with coverage (xdebug/pcov), explicit — never part of `make test`
	vendor/bin/pest --coverage --min=80

ci: quality test ## Full CI pass (quality + tests)
	@echo "\033[32m✅ CI passed\033[0m"
