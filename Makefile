.PHONY: help lint lint-check stan rector rector-fix mutate quality quality-fix test ci \
        e2e-migrate e2e-laravel e2e-hub e2e-smoke e2e-test e2e-up e2e-down

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

mutate: ## Run Infection mutation testing
	vendor/bin/infection --threads=4

quality: lint-check stan rector ## Run all quality checks (no fix)

quality-fix: lint rector-fix ## Fix code style and apply Rector

test: ## Run test suite with coverage
	vendor/bin/pest --coverage --min=80

ci: quality test ## Full CI pass (quality + tests)
	@echo "\033[32m✅ CI passed\033[0m"

# ── Realtime-collaboration e2e stand (P1.1 — see e2e/README.md) ──────────────────────
e2e-migrate: ## e2e: migrate the host DB (persistent SQLite by default)
	vendor/bin/testbench migrate:fresh --force

e2e-laravel: ## e2e: serve the live Laravel host (:8000)
	vendor/bin/testbench serve --host 127.0.0.1 --port 8000

e2e-hub: ## e2e: run the Hocuspocus collaboration hub (:1234)
	cd hocuspocus-server && npm install --no-audit --no-fund && \
		VAULTER_BACKEND_URL=http://localhost:8000 VAULTER_API_PREFIX=api/vaulter node src/index.js

e2e-smoke: ## e2e: headless 2-client collab smoke (stack must be up)
	cd e2e && npm install --no-audit --no-fund && npm run smoke

e2e-test: ## e2e: Playwright 2-browser collab e2e (stack must be up)
	cd e2e && npm install --no-audit --no-fund && npm test

e2e-up: ## e2e: bring up the full stack (postgres+laravel+hub+vite) via docker-compose
	cd e2e && docker compose up --build -d

e2e-down: ## e2e: tear down the docker-compose stack
	cd e2e && docker compose down -v
