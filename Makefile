DC := docker compose
CONSUMER_VERSION ?= 1.0.0
PROVIDER_VERSION ?= 1.0.0

.PHONY: help
help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

## --- setup -------------------------------------------------------------
.PHONY: init
init: ## собрать образ, поднять брокер и провайдера, поставить зависимости
	@test -f docker-compose.override.yml || cp docker-compose.override.example.yml docker-compose.override.yml
	docker build -t pact-sandbox-php .
	$(DC) up -d broker provider consumer
	$(MAKE) install
	@echo "\nBroker UI: http://localhost:9292 (pact / pact)"

.PHONY: install
install: ## composer install для обоих сервисов
	$(DC) run --rm -e COMPOSER_ALLOW_SUPERUSER=1 consumer composer install --no-interaction
	$(DC) run --rm -e COMPOSER_ALLOW_SUPERUSER=1 verifier composer install --no-interaction

.PHONY: up
up: ## поднять брокер и провайдера
	$(DC) up -d broker provider consumer

.PHONY: down
down: ## погасить всё и стереть данные брокера
	$(DC) down -v

## --- шаг 1: consumer ---------------------------------------------------
.PHONY: consumer-test
consumer-test: ## прогнать pact-тесты консьюмера -> consumer/pacts/*.json
	$(DC) run --rm consumer vendor/bin/phpunit

.PHONY: pact
pact: ## показать сгенерированный контракт
	@cat consumer/pacts/order-service-user-service.json

## --- шаг 2: публикация -------------------------------------------------
.PHONY: publish
publish: ## опубликовать контракт в брокер
	$(DC) run --rm pact-cli publish /pacts \
		--consumer-app-version=$(CONSUMER_VERSION) --branch=main

## --- шаг 3: provider ---------------------------------------------------
.PHONY: verify
verify: ## провайдер забирает контракты из брокера и проверяет себя
	$(DC) up -d --no-recreate provider
	$(DC) run --rm -e PROVIDER_VERSION=$(PROVIDER_VERSION) verifier

## --- шаг 4: деплой -----------------------------------------------------
.PHONY: can-i-deploy
can-i-deploy: ## можно ли выкатить консьюмера в production
	$(DC) run --rm pact-cli can-i-deploy \
		--pacticipant order-service --version $(CONSUMER_VERSION) \
		--to-environment production

.PHONY: record-deployment
record-deployment: ## отметить в брокере, что версии уехали в production
	$(DC) run --rm pact-cli record-deployment \
		--pacticipant user-service --version $(PROVIDER_VERSION) --environment production
	$(DC) run --rm pact-cli record-deployment \
		--pacticipant order-service --version $(CONSUMER_VERSION) --environment production

.PHONY: matrix
matrix: ## матрица совместимости версий из брокера
	$(DC) run --rm pact-cli matrix --pacticipant order-service --pacticipant user-service

## --- весь цикл одной командой ------------------------------------------
.PHONY: demo
demo: ## consumer-test -> publish -> verify -> can-i-deploy -> record-deployment
	$(MAKE) consumer-test
	$(MAKE) publish
	$(MAKE) verify
	$(MAKE) record-deployment
	$(MAKE) can-i-deploy

## --- демонстрация ломающего изменения ----------------------------------
.PHONY: break
break: ## провайдер переименовывает name -> full_name (ломающее изменение)
	BREAK_CONTRACT=1 $(DC) up -d --force-recreate provider
	@echo "provider теперь отдаёт full_name вместо name"

.PHONY: fix
fix: ## вернуть провайдера в нормальное состояние
	BREAK_CONTRACT=0 $(DC) up -d --force-recreate provider

.PHONY: logs
logs: ## логи провайдера: только реальные запросы, без служебных строк php -S
	$(DC) logs -f provider | grep --line-buffered -vE 'Accepted|Closing|Closed without sending'
