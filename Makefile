# Convenience targets for the Docker workflow.
# Run `make help` to list them.

ENV_FILE ?= .env.docker
COMPOSE  ?= docker compose --env-file $(ENV_FILE)
PROD     := -f docker-compose.yml -f docker-compose.prod.yml

.DEFAULT_GOAL := help

.PHONY: help build up down restart logs ps shell artisan migrate fresh tinker \
        cache-clear queue-restart prod-up prod-down prod-pull push key

help: ## Show this help
	@awk 'BEGIN{FS=":.*##"; printf "Usage: make <target>\n\nTargets:\n"} \
		/^[a-zA-Z_-]+:.*##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

build: ## Build the app image
	$(COMPOSE) build

up: ## Start the full stack (dev override is auto-loaded)
	$(COMPOSE) up -d

down: ## Stop the stack
	$(COMPOSE) down

restart: ## Restart the app container
	$(COMPOSE) restart app queue scheduler

logs: ## Tail logs (CTRL-C to stop)
	$(COMPOSE) logs -f --tail=200

ps: ## List running services
	$(COMPOSE) ps

shell: ## Open a shell in the app container
	$(COMPOSE) exec app bash

artisan: ## Run an artisan command:  make artisan cmd="route:list"
	$(COMPOSE) exec app php artisan $(cmd)

migrate: ## Run database migrations
	$(COMPOSE) exec app php artisan migrate --force

fresh: ## Drop & re-run migrations (DANGEROUS — dev only)
	$(COMPOSE) exec app php artisan migrate:fresh --force

tinker: ## Open Laravel Tinker
	$(COMPOSE) exec app php artisan tinker

cache-clear: ## Clear & rebuild Laravel caches
	$(COMPOSE) exec app php artisan optimize:clear
	$(COMPOSE) exec app php artisan optimize

queue-restart: ## Tell queue workers to restart gracefully
	$(COMPOSE) exec app php artisan queue:restart

key: ## Print a fresh APP_KEY (paste into $(ENV_FILE))
	$(COMPOSE) run --rm --no-deps app php artisan key:generate --show

# --- Production targets ------------------------------------------------------

prod-pull: ## Pull the configured APP_IMAGE on the production host
	$(COMPOSE) $(PROD) pull

prod-up: ## Start stack on a production host using prod overlay
	$(COMPOSE) $(PROD) up -d

prod-down: ## Stop stack on a production host
	$(COMPOSE) $(PROD) down

push: ## Tag and push the locally built image (set APP_IMAGE in $(ENV_FILE))
	docker push $$(grep '^APP_IMAGE=' $(ENV_FILE) | cut -d= -f2-)
