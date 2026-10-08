# Task runner for strontiumcorp/laravel-mfa. Run `make` to list targets.
#
# Tests run in parallel. Coverage needs a coverage driver: with Xdebug the
# mode is passed explicitly to every parallel worker (PCOV needs nothing).

PHP        ?= php
PEST       := vendor/bin/pest
PROCESSES  ?= $(shell nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 4)
PARALLEL   := --parallel --processes=$(PROCESSES)
XDEBUG_COV := -d xdebug.mode=coverage
MIN_COVERAGE ?= 85

.DEFAULT_GOAL := help
.PHONY: help install test test-filter coverage lint format analyse ci test-laravel test-matrix typecheck-stubs release clean

help: ## List available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

install: ## Install Composer dependencies
	composer install --no-interaction

test: ## Run the test suite in parallel
	$(PEST) $(PARALLEL)

test-filter: ## Run tests matching FILTER, e.g. make test-filter FILTER="SendLimits"
	@test -n "$(FILTER)" || (echo 'Usage: make test-filter FILTER="pattern"' && exit 1)
	$(PEST) --filter="$(FILTER)"

coverage: ## Run tests in parallel with coverage (fails under MIN_COVERAGE, default 85)
	$(PHP) $(XDEBUG_COV) $(PEST) $(PARALLEL) --passthru-php="'-d' 'xdebug.mode=coverage'" --coverage --min=$(MIN_COVERAGE)

lint: ## Check code style (Pint)
	vendor/bin/pint --test

format: ## Fix code style (Pint)
	vendor/bin/pint

analyse: ## Static analysis (PHPStan / Larastan)
	vendor/bin/phpstan analyse --memory-limit=1G --no-progress

ci: lint analyse test ## Everything CI runs: lint, analyse, test

test-laravel: ## Run the suite against another Laravel major in a scratch copy, e.g. make test-laravel VERSION=11
	@test -n "$(VERSION)" || (echo 'Usage: make test-laravel VERSION=11|12|13' && exit 1)
	scripts/test-laravel.sh $(VERSION)

test-matrix: ## Run the suite against Laravel 11, 12 and 13
	scripts/test-laravel.sh 11 && scripts/test-laravel.sh 12 && scripts/test-laravel.sh 13

typecheck-stubs: ## Type-check the React stubs against host apps, e.g. make typecheck-stubs APPS="../podcast-flow ../artistly"
	@test -n "$(APPS)" || (echo 'Usage: make typecheck-stubs APPS="../app-one ../app-two"' && exit 1)
	scripts/typecheck-stubs.sh $(APPS)

release: ## Tag a release and update CHANGELOG.md, e.g. make release ARGS="--dry-run" (see scripts/release.sh --help)
	scripts/release.sh $(ARGS)

clean: ## Remove caches and build output
	rm -rf build .phpunit.cache coverage
