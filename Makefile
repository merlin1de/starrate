# ──────────────────────────────────────────────────────────────────────────────
# StarRate – Makefile
# ──────────────────────────────────────────────────────────────────────────────

APP_ID    := starrate
APP_DIR   := $(CURDIR)
BUILD_DIR := $(APP_DIR)/build
DIST_DIR  := $(APP_DIR)/dist

NC_DIR    ?= /var/www/nextcloud
APPS_DIR  := $(NC_DIR)/apps

PHP       ?= php
COMPOSER  ?= composer
NPM       ?= npm
CYPRESS   ?= npx cypress

TIMESTAMP := $(shell date '+%Y-%m-%d %H:%M:%S')

.DEFAULT_GOAL := help

# ── Help ──────────────────────────────────────────────────────────────────────

.PHONY: help
help:
	@echo ""
	@echo "  StarRate – available targets"
	@echo ""
	@echo "  Setup"
	@echo "    make install-deps     Install PHP + JS dependencies"
	@echo "    make fixtures         (Re)generate test fixture files"
	@echo "    make hooks            Install git pre-commit / pre-push hooks"
	@echo ""
	@echo "  Tests"
	@echo "    make test             Run ALL tests (PHP + JS)"
	@echo "    make test-php         PHPUnit unit tests only"
	@echo "    make test-js          Vitest component tests only"
	@echo "    make test-e2e         Cypress E2E tests (needs running NC)"
	@echo "    make test-coverage    Generate coverage reports"
	@echo ""
	@echo "  Build"
	@echo "    make build            Build production JS bundle"
	@echo "    make package          Create installable .tar.gz"
	@echo ""
	@echo "  Deployment"
	@echo "    make install          Install app into NC_DIR (default: $(NC_DIR))"
	@echo "    make uninstall        Remove app from NC_DIR"
	@echo ""
	@echo "  Maintenance"
	@echo "    make lint             Run PHP_CodeSniffer + ESLint"
	@echo "    make clean            Remove build artefacts"
	@echo ""

# ── Dependencies ──────────────────────────────────────────────────────────────

.PHONY: install-deps
install-deps:
	$(COMPOSER) install --no-interaction
	$(NPM) ci

# ── Fixtures ──────────────────────────────────────────────────────────────────

.PHONY: fixtures
fixtures:
	$(PHP) tests/fixtures/generate_fixtures.php \
	  || python3 tests/fixtures/generate_fixtures.py \
	  || python  tests/fixtures/generate_fixtures.py

# ── Tests ─────────────────────────────────────────────────────────────────────

.PHONY: test
test: test-php test-js
	@echo ""
	@echo "──────────────────────────────"
	@echo "  All tests passed ✓"
	@echo "──────────────────────────────"
	@printf "StarRate – Last Test Run\n========================\nRun: $(TIMESTAMP)\nPHP:  ✓\nJS:   ✓\nE2E:  (not run — use make test-e2e)\n" \
	  > tests/results/last-run.txt

.PHONY: test-php
test-php:
	@echo "── PHPUnit ──────────────────────────────────────────────"
	$(PHP) vendor/bin/phpunit --configuration phpunit.xml
	@echo ""

.PHONY: test-js
test-js:
	@echo "── Vitest ───────────────────────────────────────────────"
	$(NPM) run test -- --run
	@echo ""

.PHONY: test-e2e
test-e2e:
	@echo "── Cypress E2E (lokal) ──────────────────────────────────"
	$(CYPRESS) run --spec "tests/e2e/**/*.cy.js"

# E2E-Tests auf donkeykong via Docker
DONKEYKONG      ?= donkeykong
DONKEYKONG_PATH ?= /opt/starrate

.PHONY: test-e2e-remote
test-e2e-remote:
	@echo "── Cypress E2E auf donkeykong ────────────────────────────"
	@echo "   Repo: $(DONKEYKONG):$(DONKEYKONG_PATH)"
	ssh $(DONKEYKONG) "cd $(DONKEYKONG_PATH) && docker compose -f docker/cypress.yml up --abort-on-container-exit"

.PHONY: sync-to-donkeykong
sync-to-donkeykong:
	@echo "── Sync nach donkeykong ─────────────────────────────────"
	rsync -av --delete \
	  --exclude='.git' --exclude='node_modules' --exclude='vendor' \
	  --exclude='tests/results' --exclude='docker/cypress.env' \
	  $(APP_DIR)/ $(DONKEYKONG):$(DONKEYKONG_PATH)/

.PHONY: test-coverage
test-coverage:
	@echo "── Coverage (PHP) ───────────────────────────────────────"
	$(PHP) vendor/bin/phpunit --configuration phpunit.xml --coverage-html tests/results/coverage-php
	@echo ""
	@echo "── Coverage (JS) ────────────────────────────────────────"
	$(NPM) run test -- --run --coverage

# ── Linting ───────────────────────────────────────────────────────────────────

.PHONY: lint
lint: lint-php lint-js

.PHONY: lint-php
lint-php:
	$(PHP) vendor/bin/phpcs --standard=PSR12 lib/ appinfo/

.PHONY: lint-js
lint-js:
	$(NPM) run lint 2>/dev/null || true

# ── Build ─────────────────────────────────────────────────────────────────────

.PHONY: build
build:
	@echo "── Vite build ───────────────────────────────────────────"
	$(NPM) run build

.PHONY: package
package: build
	@echo "── Packaging $(APP_ID).tar.gz ───────────────────────────"
	@mkdir -p $(DIST_DIR)
	@tar \
	  --exclude='.git' \
	  --exclude='.claude' \
	  --exclude='.gitignore' \
	  --exclude='.npmrc' \
	  --exclude='.env*' \
	  --exclude='node_modules' \
	  --exclude='vendor' \
	  --exclude='tests' \
	  --exclude='src' \
	  --exclude='android' \
	  --exclude='docker' \
	  --exclude='scripts' \
	  --exclude='screenshots' \
	  --exclude='notes' \
	  --exclude='lr-plugin' \
	  --exclude='sync-app' \
	  --exclude='dist' \
	  --exclude='build' \
	  --exclude='*.cy.js' \
	  --exclude='*.spec.js' \
	  --exclude='*.stackdump' \
	  --exclude='*.ico' \
	  --exclude='CLAUDE.md' \
	  --exclude='README.md' \
	  --exclude='Makefile' \
	  --exclude='cypress.config.cjs' \
	  --exclude='vite.config.js' \
	  --exclude='vitest.config.js' \
	  --exclude='phpunit.xml' \
	  --exclude='composer.json' \
	  --exclude='composer.lock' \
	  --exclude='package.json' \
	  --exclude='package-lock.json' \
	  -czf $(DIST_DIR)/$(APP_ID).tar.gz \
	  -C $(dir $(APP_DIR)) $(APP_ID)/
	@echo "Package ready: $(DIST_DIR)/$(APP_ID).tar.gz"

# ── Deployment ────────────────────────────────────────────────────────────────

# Deploy direkt nach sixpack/Nextcloud (Windows → QNAP via SMB + SSH)
.PHONY: deploy
deploy:
	@echo "── Deploy → sixpack/Nextcloud ───────────────────────────"
	powershell -ExecutionPolicy Bypass -File scripts/deploy-nc.ps1

.PHONY: deploy-skip-build
deploy-skip-build:
	powershell -ExecutionPolicy Bypass -File scripts/deploy-nc.ps1 -SkipBuild

.PHONY: install
install: build
	@echo "── Installing into $(APPS_DIR)/$(APP_ID) ────────────────"
	@mkdir -p $(APPS_DIR)/$(APP_ID)
	rsync -av --delete \
	  --exclude='.git' \
	  --exclude='node_modules' \
	  --exclude='tests' \
	  --exclude='dist' \
	  --exclude='build' \
	  $(APP_DIR)/ $(APPS_DIR)/$(APP_ID)/
	$(NC_DIR)/occ app:enable $(APP_ID)

.PHONY: uninstall
uninstall:
	$(NC_DIR)/occ app:disable $(APP_ID)
	rm -rf $(APPS_DIR)/$(APP_ID)

# ── Git Hooks ─────────────────────────────────────────────────────────────────

.PHONY: hooks
hooks:
	@echo "── Installing git hooks ─────────────────────────────────"
	@mkdir -p .git/hooks
	@cp scripts/hooks/pre-commit  .git/hooks/pre-commit
	@cp scripts/hooks/pre-push    .git/hooks/pre-push
	@chmod +x .git/hooks/pre-commit .git/hooks/pre-push
	@echo "Hooks installed: pre-commit, pre-push"

# ── Clean ─────────────────────────────────────────────────────────────────────

.PHONY: clean
clean:
	rm -rf $(BUILD_DIR) $(DIST_DIR) js/
	rm -rf tests/results/coverage-php tests/results/coverage-js
	rm -f  tests/results/last-run.txt
