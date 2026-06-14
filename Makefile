.PHONY: help lint lint-check stan rector rector-fix mutate quality quality-fix test ci

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
