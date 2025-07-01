.PHONY: help setup-dev setup-dev-full setup-prod setup-prod-full sandbox-toggle start stop restart up status logs shell rootshell sandbox sandbox-control artisan composer urls ports clean reset fix-permissions

# All functionality is delegated to docker/manager.sh script
MANAGER := ./docker/manager.sh

# Ensure manager script is executable
check-scripts:
	@if [ ! -x "$(MANAGER)" ]; then \
		echo "🔧 Fixing script permissions..."; \
		chmod +x $(MANAGER); \
	fi

help: ## Show help
	@$(MANAGER) help

# Environment setup
setup-dev: ## Setup development environment (lightweight, no sandbox)
	@$(MANAGER) setup-dev

setup-dev-full: ## Setup development environment (with sandbox support)
	@$(MANAGER) setup-dev-full

setup-prod: ## Setup production environment (lightweight, no sandbox)
	@$(MANAGER) setup-prod

setup-prod-full: ## Setup production environment (with sandbox support)
	@$(MANAGER) setup-prod-full

# Sandbox management
sandbox-toggle: ## Toggle sandbox on/off (use: make sandbox-toggle action="enable" or action="disable")
	@$(MANAGER) sandbox-toggle "$(action)"

sandbox-enable: ## Enable sandbox support
	@$(MANAGER) sandbox-toggle enable

sandbox-disable: ## Disable sandbox support
	@$(MANAGER) sandbox-toggle disable

# Container management
start: check-scripts ## Start containers (build if needed)
	@$(MANAGER) start

stop: check-scripts ## Stop containers
	@$(MANAGER) stop

restart: check-scripts ## Restart containers
	@$(MANAGER) restart

up: check-scripts ## Start containers in foreground
	@$(MANAGER) up

status: ## Show container status
	@$(MANAGER) status

# Logging
logs: ## Show application logs
	@$(MANAGER) logs

logs-all: ## Show all container logs
	@$(MANAGER) logs all

logs-service: ## Show specific service logs (use: make logs-service service="mysql")
	@$(MANAGER) logs "$(service)"

# Shell access
shell: ## Open shell as depthnet user
	@$(MANAGER) shell

rootshell: ## Open shell as root user
	@$(MANAGER) rootshell

# Sandbox shell access
sandbox: ## Open shell in sandbox manager or specific sandbox (use: make sandbox name="test2")
	@$(MANAGER) sandbox "$(name)"

sandbox-control: ## Control sandbox containers (use: make sandbox-control action="list" or action="start" name="test")
	@$(MANAGER) sandbox-control "$(action)" "$(name)"

# Sandbox shortcuts
sandbox-list: ## List all sandbox containers
	@$(MANAGER) sandbox-control list

sandbox-cleanup: ## Destroy all sandbox containers
	@$(MANAGER) sandbox-control cleanup

# Laravel commands
artisan: ## Run artisan command (use: make artisan cmd="migrate")
	@$(MANAGER) artisan "$(cmd)"

composer: ## Run composer command (use: make composer cmd="install")
	@$(MANAGER) composer "$(cmd)"

# Information
urls: ## Show application URLs
	@$(MANAGER) urls

ports: ## Show port resolution info
	@$(MANAGER) ports

# Maintenance
clean: ## Clean up containers
	@$(MANAGER) clean

reset: ## Complete reset (containers, volumes, images)
	@$(MANAGER) reset

fix-permissions: ## Fix file permissions after sudo usage
	@$(MANAGER) fix-permissions

# Database shortcuts
migrate: ## Run database migrations
	@$(MANAGER) artisan "migrate"

migrate-fresh: ## Fresh migration with seeding
	@$(MANAGER) artisan "migrate:fresh --seed"

migrate-rollback: ## Rollback last migration
	@$(MANAGER) artisan "migrate:rollback"

seed: ## Run database seeders
	@$(MANAGER) artisan "db:seed"

# Development shortcuts
install: ## Install PHP dependencies
	@$(MANAGER) composer "install"

update: ## Update PHP dependencies
	@$(MANAGER) composer "update"

dump-autoload: ## Dump composer autoload
	@$(MANAGER) composer "dump-autoload"

clear-cache: ## Clear all Laravel caches
	@$(MANAGER) artisan "cache:clear"
	@$(MANAGER) artisan "config:clear"
	@$(MANAGER) artisan "route:clear"
	@$(MANAGER) artisan "view:clear"

optimize: ## Optimize Laravel for production
	@$(MANAGER) artisan "config:cache"
	@$(MANAGER) artisan "route:cache"
	@$(MANAGER) artisan "view:cache"

# Quick setup aliases
dev: setup-dev ## Alias for setup-dev
	@echo "Development environment ready!"

full: setup-dev-full ## Alias for setup-dev-full  
	@echo "Full development environment ready!"

prod: setup-prod ## Alias for setup-prod
	@echo "Production environment ready!"
