# Shared targets for imwald / gitcitadel app stacks (php + prewarm only).
# Include from deploy/*/Makefile.hub after setting HUB_COMPOSE.

ARTICLES_FROM ?= -2 month
ARTICLES_TO ?= now
COMPOSE := docker compose -f $(HUB_COMPOSE)
# Set DB_DIR in the including Makefile (e.g. ../unfold-db).
DB_DIR ?= ../unfold-db

.PHONY: help pull up down ps restart restart-app up-app down-app migrate prewarm-once articles-get backfill shell logs-php logs-prewarm logs-db

help:
	@echo "Hub app stack (make -f Makefile.hub <target>)"
	@echo "  pull          - docker compose pull (php, prewarm)"
	@echo "  up            - start php + prewarm (requires unfold-db up)"
	@echo "  down          - stop php + prewarm (MySQL keeps running)"
	@echo "  restart       - restart php + prewarm only"
	@echo "  restart-app   - same as restart"
	@echo "  up-app        - start php + prewarm"
	@echo "  down-app      - stop php + prewarm"
	@echo "  ps            - service status"
	@echo "  migrate       - Doctrine migrations in php"
	@echo "  prewarm-once  - one-shot app:prewarm"
	@echo "  articles-get  - Nostr backfill (ARTICLES_FROM / ARTICLES_TO)"
	@echo "  backfill      - migrate + articles:get + prewarm-once"
	@echo "  shell         - shell in php"
	@echo "  logs-php      - php logs"
	@echo "  logs-prewarm  - prewarm logs"
	@echo "  logs-db       - MySQL logs (unfold-db stack)"
	@echo "Variables: HUB_COMPOSE, ARTICLES_FROM=$(ARTICLES_FROM), ARTICLES_TO=$(ARTICLES_TO)"

pull:
	$(COMPOSE) pull

up: up-app

up-app:
	$(COMPOSE) up -d php prewarm

down: down-app

down-app:
	$(COMPOSE) stop php prewarm

ps:
	$(COMPOSE) ps

restart: restart-app

restart-app:
	$(COMPOSE) restart php prewarm

migrate:
	$(COMPOSE) exec -T php php bin/console doctrine:migrations:migrate --no-interaction

prewarm-once:
	$(COMPOSE) exec -T php php bin/console app:prewarm

articles-get:
	$(COMPOSE) exec -T php php bin/console articles:get -- '$(ARTICLES_FROM)' '$(ARTICLES_TO)'

backfill: up-app migrate articles-get prewarm-once
	@echo "Backfill done."

shell:
	$(COMPOSE) exec php sh

logs-php:
	$(COMPOSE) logs -f php

logs-prewarm:
	$(COMPOSE) logs -f prewarm

logs-db:
	@$(MAKE) -f Makefile.hub -C $(DB_DIR) logs-db
