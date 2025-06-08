# Auto-detect user
DOCKER_UID := $(shell id -u)
DOCKER_GID := $(shell id -g)

.PHONY: up down build logs shell clean reset restart start

up:
	@echo "Using UID: $(DOCKER_UID), GID: $(DOCKER_GID)"
	DOCKER_UID=$(DOCKER_UID) DOCKER_GID=$(DOCKER_GID) docker compose up -d app

down:
	docker compose down

build:
	DOCKER_UID=$(DOCKER_UID) DOCKER_GID=$(DOCKER_GID) docker compose build --no-cache app

start: build up

logs:
	docker compose logs -f app

shell:
	docker compose exec --user appuser app bash

rootshell:
	docker compose exec app bash

clean:
	docker compose down -v
	docker network prune -f

reset:
	docker compose down -v --rmi all
	docker network prune -f
	docker system prune -f

restart: clean start
