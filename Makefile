# Shortcuts for the Documentaliste dev environment.
# Target GLPI major version with GLPI=11 or GLPI=12 (default), e.g. `make check GLPI=11`.
# Override the compose tool with: make up COMPOSE_BIN="docker compose"

GLPI ?= 12
PLUGIN := documentaliste

ifeq ($(GLPI),11)
  export GLPI_RUNTIME_TAG := 11.0.13
  export GLPI_BRANCH      := 11.0.x
  export QA_PHP           := 8.2
  export GLPI_PORT        ?= 8091
else ifeq ($(GLPI),12)
  export GLPI_RUNTIME_TAG := 12.0.2
  export GLPI_BRANCH      := 12.0.x
  export QA_PHP           := 8.3
  export GLPI_PORT        ?= 8090
else
  $(error Unsupported GLPI=$(GLPI), use 11 or 12)
endif

COMPOSE_BIN ?= podman-compose
# One compose project per GLPI version: separate containers and volumes.
COMPOSE := $(COMPOSE_BIN) -p documentaliste-glpi$(GLPI)

GLPI_EXEC := $(COMPOSE) exec -T glpi
QA_EXEC   := $(COMPOSE) --profile qa exec -T qa
QA_GLPI   := $(COMPOSE) --profile qa exec -T -u www-data -w /var/www/glpi qa

.PHONY: help up down reset logs console plugin-install plugin-reinstall cron \
        qa-up qa-down qa-setup test phpstan cs cs-fix rector rector-fix headers lint check check-all build

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

# --- Runtime GLPI (http://localhost:8090 for v12, 8091 for v11; glpi/glpi) ---

build: ## (Re)build the local images (official images + Tesseract OCR)
	$(COMPOSE) --profile qa build glpi qa

up: ## Start GLPI runtime + database
	$(COMPOSE) up -d glpi db

down: ## Stop all containers (keeps data)
	$(COMPOSE) --profile qa down

reset: ## Stop and DELETE all data volumes
	$(COMPOSE) --profile qa down -v

logs: ## Follow GLPI logs
	$(COMPOSE) logs -f glpi

console: ## Run a GLPI console command: make console CMD="plugin:list"
	$(GLPI_EXEC) bin/console $(CMD)

plugin-install: ## Install + activate the plugin in the runtime GLPI
	$(GLPI_EXEC) bin/console plugin:install --no-interaction --username=glpi $(PLUGIN)
	$(GLPI_EXEC) bin/console plugin:activate --no-interaction $(PLUGIN)

plugin-reinstall: ## Uninstall then install the plugin (drops plugin data)
	-$(GLPI_EXEC) bin/console plugin:uninstall --no-interaction $(PLUGIN)
	$(MAKE) plugin-install

cron: ## Force-run the plugin automatic action in the runtime GLPI
	$(GLPI_EXEC) php front/cron.php --force analyze

# --- QA (official plugin CI image) -------------------------------------------

qa-up: ## Start the QA container + test database
	$(COMPOSE) --profile qa up -d qa db-test

qa-down: ## Stop the QA containers
	$(COMPOSE) --profile qa stop qa db-test

qa-setup: qa-up ## Install plugin deps, test database and plugin (run once after qa-up)
	$(QA_EXEC) composer install --no-interaction --no-progress
	$(QA_GLPI) bin/console database:install --no-interaction --force --reconfigure --db-name=glpi --db-host=db-test --db-user=root --db-password= --strict-configuration
	$(QA_GLPI) bin/console plugin:install --no-interaction --username=glpi $(PLUGIN)
	$(QA_GLPI) bin/console plugin:activate --no-interaction $(PLUGIN)

test: ## Run PHPUnit (FILTER=... to filter)
	$(QA_EXEC) ../../vendor/bin/phpunit $(if $(FILTER),--filter '$(FILTER)')

phpstan: ## Run PHPStan (level max)
	$(QA_EXEC) ../../vendor/bin/phpstan analyze --memory-limit=2G --no-progress

cs: ## Check coding standards (php-cs-fixer, GLPI baseline)
	$(QA_EXEC) env PHP_CS_FIXER_IGNORE_ENV=1 ../../vendor/bin/php-cs-fixer check --config=.php-cs-fixer.php --show-progress=none --diff

cs-fix: ## Fix coding standards
	$(QA_EXEC) env PHP_CS_FIXER_IGNORE_ENV=1 ../../vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --show-progress=none

lint: ## PHP syntax lint
	$(QA_EXEC) ../../vendor/bin/parallel-lint --show-deprecated --exclude ./vendor/ --no-progress .

rector: ## Rector dry-run (GLPI baseline)
	$(QA_EXEC) ../../vendor/bin/rector process --dry-run

rector-fix: ## Apply Rector changes, then re-run cs-fix
	$(QA_EXEC) ../../vendor/bin/rector process
	$(MAKE) cs-fix

headers: ## Check licence headers (tools/HEADER)
	$(QA_GLPI) bin/console tools:licence_headers_check --plugin=$(PLUGIN)

check: lint cs rector headers phpstan test ## Everything CI runs, for the selected GLPI version

check-all: ## `make check` on GLPI 11 then GLPI 12
	$(MAKE) check GLPI=11
	$(MAKE) check GLPI=12
