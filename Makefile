.DEFAULT_GOAL := help
.PHONY: help up down restart build logs shell db psql install migrate migration test test-backend test-frontend lint content-validate fresh

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

fresh: ## Drop, recreate and migrate the database
	$(PHP) php bin/console doctrine:database:drop --force --if-exists
	$(PHP) php bin/console doctrine:database:create
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction
