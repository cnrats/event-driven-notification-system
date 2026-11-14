COMPOSE     = docker compose
COMPOSE_DEV = docker compose -f docker-compose.yml -f compose.dev.yml
RUN         = $(COMPOSE) run --rm --no-deps -e RUN_MIGRATIONS=0 app

.PHONY: help up down dev logs shell test lint fix demo scenario

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Build and start everything (API on :8080)
	$(COMPOSE) up -d --build

down: ## Stop everything and remove volumes
	$(COMPOSE) down -v

dev: ## Start with the working tree mounted into the containers
	$(COMPOSE_DEV) up -d --build

logs: ## Follow application logs (JSON)
	$(COMPOSE) logs -f app horizon sweeper

shell: ## Open a shell in the API container
	$(COMPOSE) exec app sh

test: ## Run the whole test suite (real MySQL and Redis)
	$(COMPOSE) up -d mysql redis
	$(RUN) php artisan test

lint: ## Pint, Larastan and Rector (dry run)
	$(RUN) sh -c "vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/rector process --dry-run"

fix: ## Apply Pint and Rector fixes
	$(RUN) sh -c "vendor/bin/rector process && vendor/bin/pint"

scenario: ## Run one scenario, e.g. `make scenario name=burst`
	$(COMPOSE) exec app php artisan scenario:run $(name)

demo: ## Run every scenario and write the results to docs/results
	$(COMPOSE) exec app php artisan scenario:run all
