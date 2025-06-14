# How to deploy with Docker  (Quick start)

## Prerequisites

- Docker & Docker Compose
- Git
- Make

## Installation

```bash
# Clone repository
git clone git@github.com:rnr1721/depthnet.git
cd depthnet

# Configure Git (prevent file permission issues)
git config core.filemode false

# Start application
make start
```

## Access Points

- Application: http://localhost:8000
- phpMyAdmin: http://localhost:8001 (user: depthnet, pass: secret)

## Services

- **app** - Laravel application (PHP 8.2-FPM + Nginx + Supervisor)
- **mysql** - MySQL 8.0 database
- **phpmyadmin** - Database administration interface

## User Management

The application automatically detects your host UID/GID and creates matching user inside container to prevent permission issues:

- Container user: depthnet:depthnet
- Mapped to your host UID/GID
- All services (nginx, php-fpm) run under this user

## Available Commands

### Basic Operations

```bash
make start      # Build and start all services
make up         # Start services (without rebuild)
make down       # Stop all services
make restart    # Full restart (clean + start)
make status     # Check status of services
```

### Development

```bash
make logs       # View application logs
make shell      # Access container as depthnet
make rootshell  # Access container as root (troubleshooting)
```

### Maintenance

```bash
make build      # Rebuild application container
make clean      # Stop and remove volumes
make reset      # Complete cleanup (containers, volumes, images)
```

### Log files

- **Nginx**: ./docker/logs/nginx/
- **Supervisor**: ./docker/logs/supervisor/
- **Laravel**: ./storage/logs/

### Additional Log Commands

```bash
# Laravel logs
docker compose exec app tail -f /var/www/html/storage/logs/laravel.log

# Nginx access/error logs
docker compose exec app tail -f /var/log/nginx/access.log /var/log/nginx/error.log

# PHP-FPM logs
docker compose exec app tail -f /usr/local/var/log/php-fpm.log
```

Important! Don't forget to configure plugins in the "plugins" section of the admin panel after login. Each plugin has its own individual settings.

## Default admin account

Admin:

- **login:** admin@example.com
- **password:** admin123

User:

- **login:** test@example.com
- **password:** password
