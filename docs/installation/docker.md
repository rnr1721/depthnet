# How to deploy with Docker (Quick start)

## Prerequisites
- Docker & Docker Compose
- Git
- Make (optional, can use docker/manager.sh directly)

## Installation

```bash
# Clone repository
git clone git@github.com:rnr1721/depthnet.git
# or
git clone https://github.com/rnr1721/depthnet.git
cd depthnet
```

## Quick Start

### For Development Environment
```bash
# Setup development environment
make setup-dev
# or: ./docker/manager.sh setup-dev

# Optional: Edit .env file if you need custom settings
# vim .env

# Start application
make start
# or: ./docker/manager.sh start
```

### For Production Environment
```bash
# Setup production environment
make setup-prod
# or: ./docker/manager.sh setup-prod

# IMPORTANT: Edit .env file and configure:
# - APP_URL (your domain)
# - DB_PASSWORD (strong password)
# - DB_ROOT_PASSWORD (strong root password)
vim .env

# Start application
make start
# or: ./docker/manager.sh start
```

## Two Ways to Use

### Option 1: Make Commands (Recommended)
```bash
make help           # Show all available commands
make start          # Start containers
make urls           # Show application URLs
```

### Option 2: Direct Script Usage
```bash
./docker/manager.sh help        # Show help
./docker/manager.sh start       # Start containers
./docker/manager.sh urls        # Show URLs with port info
```

## Flexible URL Configuration

The system automatically resolves ports from your APP_URL configuration:

```bash
# Example 1: Custom port
APP_URL=http://192.168.1.5:3000

# Example 2: HTTPS (port 443 auto-detected)
APP_URL=https://mydev.local

# Example 3: HTTP without port (port 80 auto-detected)
APP_URL=http://192.168.1.5

# Example 4: Localhost with custom port
APP_URL=http://localhost:9000
```

No need for manual port exports! Just edit your `.env` file and run `make start`.

### Check Configuration
```bash
# See how your URL/port is resolved
make ports
# or: ./docker/manager.sh ports

# View resolved URLs (with port info)
make urls
# or: ./docker/manager.sh urls
```

## Alternative Setup (Pure Docker)

If Make and bash scripts are not available:

```bash
# Development
cp .env.example.docker .env
# Edit .env file as needed
docker compose up -d --build

# Production
cp .env.example.docker.prod .env
# Edit .env file (set APP_URL, passwords, etc.)
docker compose -f docker-compose.prod.yml up -d --build
```

## Access Points

After starting, check URLs with:
```bash
make urls
```

Output example:
```
 Application URLs:
    App: http://localhost:5000
    Port: 5000
    phpMyAdmin: http://localhost:8001
```

**Default Access:**
- **Application**: Automatically detected from your APP_URL
- **phpMyAdmin**: http://localhost:8001 (development only)
  - User: depthnet
  - Password: secret (or your configured DB_PASSWORD)

## Services

- **app** - Laravel application (PHP 8.2-FPM + Nginx + Supervisor)
- **mysql** - MySQL 8.0 database
- **phpmyadmin** - Database administration interface (development only)

## User Management & Security

The application automatically detects your host UID/GID and creates matching user inside container to prevent permission issues:
- Container user: depthnet:depthnet
- Mapped to your host UID/GID
- All services (nginx, php-fpm) run under this user
- **Sudo protection**: Script warns if running with sudo and uses original user's permissions

## Available Commands

### Environment Setup
```bash
make setup-dev      # Setup development environment
make setup-prod     # Setup production environment
make ports          # Show port resolution info
make urls           # Show application URLs with port
```

### Container Management
```bash
make start          # Build and start all services
make stop           # Stop all services
make restart        # Restart all services
make up             # Start services in foreground
make status         # Check status of services
```

### Development Tools
```bash
make shell          # Access container as depthnet user
make rootshell      # Access container as root
make logs           # View application logs
make logs-all       # View all container logs
```

### Laravel Commands
```bash
make artisan cmd="migrate"              # Run artisan commands
make composer cmd="install"             # Run composer commands

# Database shortcuts
make migrate                            # Run migrations
make migrate-fresh                      # Fresh migration with seeding
make seed                              # Run database seeders
```

### Maintenance
```bash
make clean              # Clean up containers and reset initialization
make reset              # Complete reset (containers, volumes, images)
make fix-permissions    # Fix file permissions after sudo usage
```

## Architecture

The Docker setup uses a **bash manager script** (`docker/manager.sh`) that handles all Docker operations with proper error handling, colored output, and automatic port resolution. The Makefile simply delegates to this script for consistency.

**Key Scripts:**
- `docker/manager.sh` - Main Docker management utility
- `docker/port-resolver.sh` - Automatic port resolution from APP_URL
- `Makefile` - Convenient shortcuts that call manager.sh

## Log Files

### File Locations
- **Nginx**: ./docker/logs/nginx/
- **Supervisor**: ./docker/logs/supervisor/
- **Laravel**: ./storage/logs/

### View Logs in Real-time
```bash
# Application logs (recommended)
make logs

# All container logs
make logs-all

# Specific Laravel logs
make shell
tail -f storage/logs/laravel.log

# Nginx logs
make shell
tail -f /var/log/nginx/access.log /var/log/nginx/error.log
```

## Configuration Tips

### Development
- Use `APP_URL=http://localhost:8000` for standard setup
- Use `APP_URL=http://192.168.1.5:3000` for network access
- Vite HMR automatically configured for Docker

### Production
- Always use HTTPS in production: `APP_URL=https://yourdomain.com`
- Set strong database passwords
- Configure proper CORS origins

## Default Admin Account

After installation, you can login with:

**Admin:**
- **Login:** admin@example.com
- **Password:** admin123

**Test User:**
- **Login:** test@example.com
- **Password:** password

## Windows Support

**Recommended approach for Windows users:**
- Use WSL2 (Windows Subsystem for Linux)
- Install Docker Desktop with WSL2 backend
- Clone and run the project inside WSL2

The bash scripts are designed for Unix-like systems and work best in WSL2 environment.

## Important Notes

- Configure plugins in the admin panel after first login
- Each plugin has individual settings
- For production, review all security settings in `.env`
- The port resolver automatically handles URL/port conflicts
- Script permissions are automatically fixed if needed

## Troubleshooting

```bash
# Check project health
make ports

# View container status
make status

# Check detailed help
make help
# or: ./docker/manager.sh help

# Reset everything if issues occur
make reset
make setup-dev  # or setup-prod
make start

# Fix permissions after sudo usage
make fix-permissions
```

### Common Issues

**Permission errors:**
```bash
make fix-permissions
```

**Port conflicts:**
```bash
make ports  # Check current port resolution
# Edit APP_URL in .env to use different port
make restart
```

**Script not executable:**
```bash
chmod +x docker/manager.sh
# or just run make start (auto-fixes permissions)
```

## Advanced Usage

### Running manager.sh directly
```bash
# All manager.sh commands
./docker/manager.sh help

# Examples
./docker/manager.sh setup-dev
./docker/manager.sh start
./docker/manager.sh shell
./docker/manager.sh artisan "migrate"
./docker/manager.sh composer "install"
```

### Environment variables
```bash
# Check how port is resolved
./docker/port-resolver.sh .env info

# Export resolved values
eval $(./docker/port-resolver.sh .env export)
echo $APP_PORT
echo $APP_URL_RESOLVED
```
