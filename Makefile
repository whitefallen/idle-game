.DEFAULT_GOAL := help
.PHONY: help up down restart build logs shell db psql install migrate migration test test-backend test-frontend lint content-validate fresh ci ci-backend ci-frontend ci-docs audit prune prune-dry docs docs-build prod-build prod-config

DC := docker compose
PHP := $(DC) exec -T php

help: ## Show available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

up: ## Start the full stack
	$(DC) up -d --build
	@echo "API      http://localhost:8080"
	@echo "Frontend http://localhost:5173"

down: ## Stop the stack
	$(DC) down

restart: down up ## Restart the stack

build: ## Rebuild images without cache
	$(DC) build --no-cache

logs: ## Tail logs from all services
	$(DC) logs -f

shell: ## Open a shell in the PHP container
	$(DC) exec php sh

psql: ## Open a psql session
	$(DC) exec postgres psql -U emberwatch -d emberwatch

install: ## Install backend dependencies
	$(PHP) composer install

migrate: ## Apply database migrations
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction

migration: ## Generate a migration from entity changes
	$(PHP) php bin/console doctrine:migrations:diff

test: test-backend test-frontend ## Run the whole test suite

test-backend: ## Run PHPUnit
	$(PHP) php vendor/bin/phpunit

test-frontend: ## Run Vitest
	$(DC) exec -T frontend npm run test -- --run

lint: ## Static analysis and layering checks
	$(PHP) php vendor/bin/phpstan analyse --no-progress
	$(PHP) php vendor/bin/deptrac analyse --no-progress --fail-on-uncovered

content-validate: ## Validate the content library
	$(PHP) php bin/console content:validate

ci: ci-backend ci-frontend ci-docs ## Run everything CI runs, in the same order

# Mirrors .github/workflows/backend.yml step for step. If the two drift, the
# point of running checks locally is lost — a green local run must mean a green
# pipeline.
ci-backend: ## Run the backend pipeline locally
	$(PHP) composer audit --no-interaction
	$(PHP) php bin/console doctrine:schema:validate
	$(PHP) php bin/console content:validate
	$(PHP) php vendor/bin/phpstan analyse --no-progress
	$(PHP) php vendor/bin/deptrac analyse --no-progress --fail-on-uncovered
	$(PHP) php vendor/bin/phpunit

ci-frontend: ## Run the frontend pipeline locally
	$(DC) run --rm --no-deps -T frontend sh -lc "npm ci && npm audit --audit-level=high && npm run typecheck && npx vitest run && npm run build"

# Mirrors .github/workflows/docs.yml's build job. No npm audit here: vitepress
# pins a vite/esbuild combo with an unresolved, dev-server-only advisory
# (GHSA-67mh-4wv8-2f99) that does not reach the built static site, and gating
# on it would fail every run for something CI cannot fix by waiting.
ci-docs: ## Run the docs build locally
	cd docs && npm ci && npm run docs:build

docs: ## Serve the documentation site locally, with live reload
	cd docs && npm install && npm run docs:dev

docs-build: ## Build the documentation site
	cd docs && npm install && npm run docs:build

audit: ## Check dependencies for known vulnerabilities
	$(PHP) composer audit --no-interaction
	$(DC) run --rm --no-deps -T frontend npm audit --audit-level=high

prune: ## Delete rows past their retention window
	$(PHP) php bin/console db:retention:prune

prune-dry: ## Report what retention would delete, without deleting it
	$(PHP) php bin/console db:retention:prune --dry-run

# The production images are built by .github/workflows/release.yml, never by
# hand for deployment. This target exists so a Dockerfile change can be proved
# to build before it is pushed — a broken production image otherwise only
# surfaces on main, where it blocks every release.
prod-build: ## Build the production images locally, without pushing
	docker build -f docker/php/Dockerfile --target prod -t emberwatch-php:local .
	docker build -f docker/web/Dockerfile -t emberwatch-web:local .

# The secrets the example file deliberately leaves blank are supplied as
# throwaway values here: the point is to prove the file parses and every
# required variable is declared, not to assemble a runnable stack.
prod-config: ## Validate compose.prod.yaml with the example environment
	POSTGRES_PASSWORD=validate APP_SECRET=validate PHP_IMAGE=php:validate WEB_IMAGE=web:validate \
		docker compose -f compose.prod.yaml --env-file .env.prod.example config > /dev/null
	@echo "compose.prod.yaml is valid."

fresh: ## Drop, recreate and migrate the database
	$(PHP) php bin/console doctrine:database:drop --force --if-exists
	$(PHP) php bin/console doctrine:database:create
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction
