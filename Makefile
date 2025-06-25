.PHONY: help setup-dev setup-prod start stop restart up status logs shell rootshell artisan composer urls ports clean reset fix-permissions

# All functionality is delegated to docker/manager.sh script
MANAGER := ./docker/manager.sh

# Ensure manager script is executable
check-scripts:
	@if [ ! -x "$(MANAGER)" ]; then \
		echo "Fixing script permissions..."; \
		chmod +x $(MANAGER); \
	fi

help: ## Show help
	@$(MANAGER) help

setup-dev: ## Setup development environment
	@$(MANAGER) setup-dev

setup-prod: ## Setup production environment
	@$(MANAGER) setup-prod

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

logs: ## Show application logs
	@$(MANAGER) logs

logs-all: ## Show all container logs
	@$(MANAGER) logs all

shell: ## Open shell as depthnet user
	@$(MANAGER) shell

rootshell: ## Open shell as root user
	@$(MANAGER) rootshell

artisan: ## Run artisan command (use: make artisan cmd="migrate")
	@$(MANAGER) artisan "$(cmd)"

composer: ## Run composer command (use: make composer cmd="install")
	@$(MANAGER) composer "$(cmd)"

urls: ## Show application URLs
	@$(MANAGER) urls

ports: ## Show port resolution info
	@$(MANAGER) ports

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

seed: ## Run database seeders
	@$(MANAGER) artisan "db:seed"
