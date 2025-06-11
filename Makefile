# Auto-detect current user UID/GID for proper file permissions in Docker
DOCKER_UID := $(shell id -u)
DOCKER_GID := $(shell id -g)

# Define all phony targets (targets that don't create files)
.PHONY: up down build logs shell clean reset restart start

# Start all services in background mode
up:
	@echo "Using UID: $(DOCKER_UID), GID: $(DOCKER_GID)"
	DOCKER_UID=$(DOCKER_UID) DOCKER_GID=$(DOCKER_GID) docker compose up -d

# Stop all services
down:
	docker compose down

# Build the application container from scratch
build:
	DOCKER_UID=$(DOCKER_UID) DOCKER_GID=$(DOCKER_GID) docker compose build --no-cache app

# Full startup: build and run services
start: build up

# Follow application logs in real-time
logs:
	docker compose logs -f app

# Access container shell as regular user
shell:
	docker compose exec --user depthnet app bash

# Access container shell as root user
rootshell:
	docker compose exec app bash

# Clean up containers and reset initialization state
clean:
	@echo "Cleaning up containers and removing initialization file..."
	rm -f ./storage/app/.docker_initialized 2>/dev/null || true
	docker compose down -v
	docker network prune -f

# Full reset: remove all generated files and containers
reset:
	@echo "Full reset: removing all files and containers..."
	rm -rf ./node_modules 2>/dev/null || true
	rm -rf ./vendor 2>/dev/null || true
	rm -f ./.env 2>/dev/null || true
	rm -f ./storage/app/.docker_initialized 2>/dev/null || true
	docker compose down -v --rmi all
	docker network prune -f
	docker system prune -f

# Restart application: clean environment and start fresh
restart: clean start
