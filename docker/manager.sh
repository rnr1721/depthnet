#!/bin/bash

# Docker Manager Utility
# Manages DepthNet Docker environment with automatic port resolution

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$PROJECT_DIR/.env"

# Colors for output
RED='\e[0;31m'
GREEN='\e[0;32m'
YELLOW='\e[1;33m'
BLUE='\e[0;34m'
NC='\e[0m' # No Color

# Logging functions
log_info() { echo -e "${BLUE} $1${NC}"; }
log_success() { echo -e "${GREEN} $1${NC}"; }
log_warning() { echo -e "${YELLOW} $1${NC}"; }
log_error() { echo -e "${RED} $1${NC}"; }

# Check if running as root or with sudo
check_sudo() {
    if [ "$(id -u)" = "0" ]; then
        if [ -n "$SUDO_USER" ]; then
            # Running with sudo - use original user
            DOCKER_UID=$(id -u "$SUDO_USER")
            DOCKER_GID=$(id -g "$SUDO_USER")
            log_warning "Running with sudo! Using original user $SUDO_USER (UID:$DOCKER_UID, GID:$DOCKER_GID)"
        else
            log_error "Do not run as root! Use your regular user account without sudo"
            exit 1
        fi
    else
        # Normal user
        DOCKER_UID=$(id -u)
        DOCKER_GID=$(id -g)
    fi
}

# Detect Docker socket GID
detect_docker_socket_gid() {
    if [ -S "/var/run/docker.sock" ]; then
        DOCKER_SOCKET_GID=$(stat -c "%g" /var/run/docker.sock 2>/dev/null || echo "999")
        log_info "Docker socket GID detected: $DOCKER_SOCKET_GID"
    else
        DOCKER_SOCKET_GID="999"
        log_warning "Docker socket not found, using default GID: $DOCKER_SOCKET_GID"
    fi
}

# Detect environment from .env file
detect_environment() {
    if [ ! -f "$ENV_FILE" ]; then
        DETECTED_ENV="none"
        ENV_TYPE="unknown"
        COMPOSE_FILE="docker-compose.yml"
        return
    fi

    DETECTED_ENV=$(grep "^APP_ENV=" "$ENV_FILE" | cut -d'=' -f2 2>/dev/null || echo "none")
    
    case "$DETECTED_ENV" in
        "production")
            ENV_TYPE="production"
            COMPOSE_FILE="docker-compose.prod.yml"
            ;;
        "local")
            ENV_TYPE="development"
            COMPOSE_FILE="docker-compose.yml"
            ;;
        *)
            ENV_TYPE="unknown"
            COMPOSE_FILE="docker-compose.yml"
            ;;
    esac
}

# Detect sandbox mode from .env
detect_sandbox_mode() {
    SANDBOX_MODE="false"
    if [ -f "$ENV_FILE" ]; then
        # Check if COMPOSE_PROFILES contains sandbox
        COMPOSE_PROFILES=$(grep "^COMPOSE_PROFILES=" "$ENV_FILE" | cut -d'=' -f2 2>/dev/null || echo "")
        if [[ "$COMPOSE_PROFILES" == *"sandbox"* ]] || [[ "$COMPOSE_PROFILES" == *"full"* ]]; then
            SANDBOX_MODE="true"
        fi
    fi
}

# Get resolved port
get_resolved_port() {
    if [ -x "$SCRIPT_DIR/port-resolver.sh" ] && [ -f "$ENV_FILE" ]; then
        "$SCRIPT_DIR/port-resolver.sh" "$ENV_FILE" port 2>/dev/null || echo "8000"
    else
        echo "8000"
    fi
}

# Get resolved URL
get_resolved_url() {
    if [ -x "$SCRIPT_DIR/port-resolver.sh" ] && [ -f "$ENV_FILE" ]; then
        "$SCRIPT_DIR/port-resolver.sh" "$ENV_FILE" url 2>/dev/null || echo "http://localhost:$(get_resolved_port)"
    else
        echo "http://localhost:$(get_resolved_port)"
    fi
}

# Setup environment files
setup_env() {
    local env_type="$1"
    local with_sandbox="$2"
    local template_file

    case "$env_type" in
        "dev")
            template_file=".env.example.docker"
            log_info "Setting up development environment..."
            ;;
        "prod")
            template_file=".env.example.docker.prod"
            log_info "Setting up production environment..."
            ;;
        *)
            log_error "Invalid environment type. Use 'dev' or 'prod'"
            exit 1
            ;;
    esac

    if [ ! -f "$template_file" ]; then
        log_error "Template file '$template_file' not found"
        exit 1
    fi

    cp "$template_file" "$ENV_FILE"
    sed -i "s/DOCKER_UID=1000/DOCKER_UID=$DOCKER_UID/" "$ENV_FILE"
    sed -i "s/DOCKER_GID=1000/DOCKER_GID=$DOCKER_GID/" "$ENV_FILE"

    # Add sandbox profile if requested
    if [ "$with_sandbox" = "true" ]; then
        if grep -q "^COMPOSE_PROFILES=" "$ENV_FILE"; then
            sed -i "s/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=sandbox/" "$ENV_FILE"
        else
            echo "COMPOSE_PROFILES=sandbox" >> "$ENV_FILE"
        fi
        log_success "Environment configured with sandbox support (UID:$DOCKER_UID, GID:$DOCKER_GID)"
    else
        log_success "Environment configured without sandbox (UID:$DOCKER_UID, GID:$DOCKER_GID)"
    fi

    if [ "$env_type" = "prod" ]; then
        log_warning "IMPORTANT: Edit .env file and set:"
        echo "   - APP_URL (your domain)"
        echo "   - DB_PASSWORD (strong password)"
        echo "   - DB_ROOT_PASSWORD (strong root password)"
    fi

    echo "You can now edit .env if needed, then run: $0 start"
}

interactive_setup() {
    local env_type="$1"
    local with_sandbox="$2"

    # If env_type not passed — ask
    if [ -z "$env_type" ]; then
        echo ""
        echo "Select environment:"
        echo "  1) Production (recommended)"
        echo "  2) Development"
        read -p "Enter choice [1]: " env_choice
        case "${env_choice:-1}" in
            "2") env_type="dev" ;;
            *)   env_type="prod" ;;
        esac
    fi

    # If sandbox not passed — ask
    if [ -z "$with_sandbox" ]; then
        echo ""
        echo "Enable sandbox support? (required for AI code execution features)"
        echo "  1) No — lightweight mode, faster startup (recommended)"
        echo "  2) Yes — full mode with sandbox"
        read -p "Enter choice [1]: " sandbox_choice
        case "${sandbox_choice:-1}" in
            "2") with_sandbox="true" ;;
            *)   with_sandbox="false" ;;
        esac
    fi

    # First do regular setup
    setup_env "$env_type" "$with_sandbox"

    echo ""
    log_info "Interactive configuration..."
    echo ""

    # APP_URL
    local current_url=$(grep "^APP_URL=" "$ENV_FILE" | cut -d'=' -f2)
    echo "Current APP_URL: $current_url"
    echo "Examples: http://localhost:8000  |  https://192.168.1.5:8443  |  https://yourdomain.com"
    read -p "Enter APP_URL (press Enter to keep current): " new_url
    if [ -n "$new_url" ]; then
        sed -i "s|^APP_URL=.*|APP_URL=$new_url|" "$ENV_FILE"
        log_success "APP_URL set to $new_url"
    fi

    # Timezone
    local current_tz=$(grep "^TZ=" "$ENV_FILE" | cut -d'=' -f2)
    echo ""
    echo "Current timezone: $current_tz"
    read -p "Enter timezone (e.g. Europe/Kyiv, press Enter to keep current): " new_tz
    if [ -n "$new_tz" ] && [ -f "/usr/share/zoneinfo/$new_tz" ]; then
        sed -i "s|^TZ=.*|TZ=$new_tz|" "$ENV_FILE"
        log_success "Timezone set to $new_tz"
    elif [ -n "$new_tz" ]; then
        log_warning "Unknown timezone '$new_tz', keeping current"
    fi

    # DB Password (prod only)
    if [ "$env_type" = "prod" ]; then
        echo ""
        read -p "Set database password? (recommended for production, press Enter to skip): " new_pass
        if [ -n "$new_pass" ]; then
            sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$new_pass|" "$ENV_FILE"
            sed -i "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=${new_pass}_root|" "$ENV_FILE"
            log_success "Database password set"
        fi
    fi

    echo ""
    log_success "Configuration complete!"
    echo "Run 'make start' to launch DepthNet."
}

# Build compose command with profile support
build_compose_cmd() {
    detect_sandbox_mode
    local profile_arg=""
    local override_arg=""

    if [ "$SANDBOX_MODE" = "true" ]; then
        profile_arg="--profile sandbox"
    fi

    if [ -f "$PROJECT_DIR/docker-compose.override.yml" ]; then
        override_arg="-f docker-compose.override.yml"
    fi

    echo "DOCKER_UID=$DOCKER_UID DOCKER_GID=$DOCKER_GID DOCKER_SOCKET_GID=$DOCKER_SOCKET_GID docker compose -f $COMPOSE_FILE $override_arg $profile_arg"
}

# Enable/disable sandbox
toggle_sandbox() {
    local action="$1"

    if [ ! -f "$ENV_FILE" ]; then
        log_error ".env file not found! Run setup first."
        exit 1
    fi

    case "$action" in
        "enable")
            if grep -q "^COMPOSE_PROFILES=" "$ENV_FILE"; then
                sed -i "s/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=sandbox/" "$ENV_FILE"
            else
                echo "COMPOSE_PROFILES=sandbox" >> "$ENV_FILE"
            fi
            log_success "Sandbox enabled! Restart containers to apply changes."
            ;;
        "disable")
            sed -i "/^COMPOSE_PROFILES=/d" "$ENV_FILE"
            log_success "Sandbox disabled! Restart containers to apply changes."
            ;;
        *)
            log_error "Usage: $0 sandbox-toggle [enable|disable]"
            exit 1
            ;;
    esac
}

# Start containers
start_containers() {
    local resolved_port=$(get_resolved_port)

    # If HTTPS — internal HTTP port must be 8000, not the HTTPS port
    local app_url=$(grep "^APP_URL=" "$ENV_FILE" | cut -d'=' -f2 | head -1)
    if echo "$app_url" | grep -q "^https://"; then
        resolved_port=8000
    fi

    detect_sandbox_mode

    log_info "Starting $ENV_TYPE environment..."
    log_info "Using port: $resolved_port (UID:$DOCKER_UID, GID:$DOCKER_GID)"
    log_info "Docker socket GID: $DOCKER_SOCKET_GID"

    # Resolve HTTPS_PORT from APP_URL if not set
    local https_port=$(grep "^HTTPS_PORT=" "$ENV_FILE" | cut -d'=' -f2 | head -1)
    if [ -z "$https_port" ]; then
        local app_url=$(grep "^APP_URL=" "$ENV_FILE" | cut -d'=' -f2 | head -1)
        if echo "$app_url" | grep -q "^https://"; then
            https_port=$(echo "$app_url" | sed 's|https://||' | cut -d':' -f2 | cut -d'/' -f1)
            https_port=${https_port:-8443}
        else
            https_port=8443
        fi
    fi

    if [ "$SANDBOX_MODE" = "true" ]; then
        log_info "Sandbox manager will be started"
    else
        log_info "Running in lightweight mode (no sandbox)"
    fi

    # Check if already running
    if docker ps --filter "name=depthnet-app-1" --filter "status=running" --quiet | grep -q .; then
        log_warning "Containers are already running! Use 'make restart' or 'make stop' first."
        exit 1
    fi

    local compose_cmd=$(build_compose_cmd)

    # Cleanup stale networks
    docker network rm depthnet_depthnet 2>/dev/null || true

    log_info "Resolved APP_PORT: $resolved_port"
    log_info "Resolved HTTPS_PORT: $https_port"
    log_info "TZ from env file: $(grep "^TZ=" "$ENV_FILE" | cut -d'=' -f2 | head -1)"

    APP_PORT="$resolved_port" HTTPS_PORT="$https_port" eval "$compose_cmd up -d --build --remove-orphans"

    log_success "Containers started"
    show_urls
}

# Build containers without starting
build_containers() {
    local resolved_port=$(get_resolved_port)
    detect_sandbox_mode

    log_info "Building $ENV_TYPE environment..."
    log_info "UID:$DOCKER_UID, GID:$DOCKER_GID, Socket GID:$DOCKER_SOCKET_GID"

    local compose_cmd=$(build_compose_cmd)
    APP_PORT="$resolved_port" eval "$compose_cmd build"

    log_success "Build completed"
}

# Stop containers
stop_containers() {
    log_info "Stopping containers..."
    local compose_cmd=$(build_compose_cmd)
    eval "$compose_cmd down"
    log_success "Containers stopped"
}

# Restart containers
restart_containers() {
    log_info "Restarting containers..."
    local compose_cmd=$(build_compose_cmd)
    eval "$compose_cmd restart"
    log_success "Containers restarted"
}

# Start in foreground
start_foreground() {
    local resolved_port=$(get_resolved_port)
    detect_sandbox_mode

    log_info "Starting $ENV_TYPE environment in foreground..."
    log_info "Using port: $resolved_port (UID:$DOCKER_UID, GID:$DOCKER_GID)"

    if [ "$SANDBOX_MODE" = "true" ]; then
        log_info "Sandbox manager will be started"
    else
        log_info "Running in lightweight mode (no sandbox)"
    fi

    local compose_cmd=$(build_compose_cmd)
    APP_PORT="$resolved_port" eval "$compose_cmd up --build"
}

# Show container status
show_status() {
    local compose_cmd=$(build_compose_cmd)
    eval "$compose_cmd ps"
}

# Show logs
show_logs() {
    local service="${1:-app}"
    local compose_cmd=$(build_compose_cmd)

    if [ "$service" = "all" ]; then
        eval "$compose_cmd logs -f"
    else
        eval "$compose_cmd logs -f $service"
    fi
}

# Open shell
open_shell() {
    local user="${1:-depthnet}"
    local compose_cmd=$(build_compose_cmd)

    log_info "Opening shell as $user user..."
    if [ "$user" = "root" ]; then
        eval "$compose_cmd exec app bash"
    else
        eval "$compose_cmd exec --user $user app bash"
    fi
}

# Open sandbox shell
open_sandbox_shell() {
    local sandbox_name="$1"
    detect_sandbox_mode

    if [ "$SANDBOX_MODE" != "true" ]; then
        log_error "Sandbox is not enabled!"
        log_info "Enable sandbox with: $0 sandbox-toggle enable"
        log_info "Then restart containers: $0 restart"
        exit 1
    fi

    local compose_cmd=$(build_compose_cmd)
    
    if [ -z "$sandbox_name" ]; then
        # Connect to sandbox manager
        log_info "Opening shell in sandbox manager..."
        local manager_container=$(docker ps --filter "name=sandbox-manager" --format "{{.Names}}" | head -1)
        
        if [ -z "$manager_container" ]; then
            log_error "Sandbox manager container not found!"
            log_info "Make sure containers are running with: $0 start"
            exit 1
        fi

        docker exec -it "$manager_container" bash
    else
        # Connect to specific sandbox
        log_info "Opening shell in sandbox: $sandbox_name"
        local sandbox_container=$(docker ps --filter "name=$sandbox_name" --format "{{.Names}}" | head -1)
        
        if [ -z "$sandbox_container" ]; then
            log_error "Sandbox container '$sandbox_name' not found!"
            log_info "Available sandboxes:"
            docker ps --filter "name=sandbox" --format "table {{.Names}}\t{{.Status}}\t{{.Image}}"
            exit 1
        fi

        docker exec -it "$sandbox_container" bash
    fi
}

# Sandbox control operations
sandbox_control() {
    local action="$1"
    local sandbox_name="$2"

    detect_sandbox_mode

    if [ "$SANDBOX_MODE" != "true" ]; then
        log_error "Sandbox is not enabled!"
        log_info "Enable sandbox with: $0 sandbox-toggle enable"
        log_info "Then restart containers: $0 restart"
        exit 1
    fi

    if [ -z "$action" ]; then
        log_error "Usage: $0 sandbox-control [action] [sandbox_name]"
        echo "Actions: list, start, stop, restart, destroy, cleanup"
        exit 1
    fi

    log_info "Executing sandbox control: $action $sandbox_name"
    local manager_container=$(docker ps --filter "name=sandbox-manager" --format "{{.Names}}" | head -1)
    
    if [ -z "$manager_container" ]; then
        log_error "Sandbox manager container not found!"
        log_info "Make sure containers are running with: $0 start"
        exit 1
    fi

    case "$action" in
        "list")
            docker exec "$manager_container" /sandbox-manager/scripts/manager.sh list
            ;;
        "start"|"stop"|"restart"|"destroy")
            if [ -z "$sandbox_name" ]; then
                log_error "Sandbox name required for action: $action"
                exit 1
            fi
            docker exec "$manager_container" /sandbox-manager/scripts/manager.sh "$action" "$sandbox_name"
            ;;
        "cleanup")
            log_warning "This will destroy ALL sandbox containers!"
            read -p "Are you sure? (y/N): " -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                docker exec "$manager_container" /sandbox-manager/scripts/manager.sh cleanup
            else
                log_info "Cleanup cancelled"
            fi
            ;;
        *)
            log_error "Unknown action: $action"
            echo "Available actions: list, start, stop, restart, destroy, cleanup"
            exit 1
            ;;
    esac
}

# Run artisan command
run_artisan() {
    local cmd="$1"
    local compose_cmd=$(build_compose_cmd)

    if [ -z "$cmd" ]; then
        log_error "Usage: $0 artisan 'command'"
        exit 1
    fi

    eval "$compose_cmd exec --user depthnet app php artisan $cmd"
}

# Run composer command
run_composer() {
    local cmd="$1"
    local compose_cmd=$(build_compose_cmd)

    if [ -z "$cmd" ]; then
        log_error "Usage: $0 composer 'command'"
        exit 1
    fi

    eval "$compose_cmd exec --user depthnet app composer $cmd"
}

# Show URLs
show_urls() {
    detect_sandbox_mode

    local app_url=$(grep "^APP_URL=" "$ENV_FILE" | cut -d'=' -f2 | head -1 2>/dev/null || echo "http://localhost:8000")
    local pma_port=$(grep "^PMA_PORT=" "$ENV_FILE" | cut -d'=' -f2 | head -1 2>/dev/null)
    pma_port=${pma_port:-8001}

    echo ""
    echo "Application URLs:"
    echo "    App: $app_url"

    if [ "$DETECTED_ENV" != "production" ] && [ -f "$ENV_FILE" ]; then
        echo "    phpMyAdmin: http://localhost:$pma_port"
    fi

    if [ "$SANDBOX_MODE" = "true" ]; then
        echo "🔧 Sandbox: Enabled"
    else
        echo "⚡ Sandbox: Disabled (lightweight mode)"
    fi
    echo ""
}

# Show port resolution info
show_port_info() {
    if [ -x "$SCRIPT_DIR/port-resolver.sh" ] && [ -f "$ENV_FILE" ]; then
        "$SCRIPT_DIR/port-resolver.sh" "$ENV_FILE" info
    else
        log_error "Port resolver script not found or .env file missing"
    fi
}

# Fix permissions
fix_permissions() {
    if [ "$(id -u)" = "0" ]; then
        log_error "Don't run fix-permissions as root!"
        exit 1
    fi

    log_info "Fixing file permissions..."
    sudo chown -R "$DOCKER_UID:$DOCKER_GID" . 2>/dev/null || true
    log_success "Permissions fixed for UID:$DOCKER_UID, GID:$DOCKER_GID"
}

# Clean up
cleanup() {
    log_info "Cleaning up..."
    rm -f ./storage/app/.docker_initialized 2>/dev/null || true
    rm -f ./public/hot 2>/dev/null || true
    docker compose -f docker-compose.yml down -v 2>/dev/null || true
    docker compose -f docker-compose.prod.yml down -v 2>/dev/null || true
    docker network prune -f
    log_success "Cleanup completed"
}

# Complete reset (containers, volumes, images)
reset_project() {
    log_warning "This will completely reset the Docker environment!"
    read -p "Are you sure? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_info "Reset cancelled"
        return 0
    fi

    log_info "Stopping and removing all containers..."
    docker compose -f docker-compose.yml down -v --remove-orphans 2>/dev/null || true
    docker compose -f docker-compose.prod.yml down -v --remove-orphans 2>/dev/null || true

    log_info "Removing Docker images..."
    docker image rm $(docker images "*depthnet*" -q) 2>/dev/null || true

    log_info "Cleaning up files..."
    rm -f ./storage/app/.docker_initialized 2>/dev/null || true
    rm -f ./public/hot 2>/dev/null || true

    log_info "Pruning Docker system..."
    docker system prune -f
    docker volume prune -f
    docker network prune -f

    log_success "Complete reset finished"
    echo "Run '$0 setup-dev' or '$0 setup-prod' to start fresh"
}

# Prune project (stop containers and remove images, keep volumes)
prune_project() {
    log_warning "This will stop containers and remove all Docker images for this project."
    log_info "Volumes (database data) will NOT be removed."
    read -p "Are you sure? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_info "Prune cancelled"
        return 0
    fi

    log_info "Stopping containers..."
    docker compose -f docker-compose.yml down --remove-orphans 2>/dev/null || true
    docker compose -f docker-compose.prod.yml down --remove-orphans 2>/dev/null || true

    log_info "Removing project images..."
    docker image rm $(docker images "*depthnet*" -q) 2>/dev/null || true

    log_info "Removing initialization flag..."
    rm -f ./storage/app/.docker_initialized 2>/dev/null || true
    rm -f ./public/hot 2>/dev/null || true

    log_info "Pruning unused Docker networks..."
    docker network prune -f

    log_success "Prune completed! Volumes preserved."
    echo "Run 'make start' to rebuild and restart."
}

# Database backup
backup_database() {
    local timestamp=$(date +"%Y-%m-%d_%H-%M")
    local backup_file="$PROJECT_DIR/backups/depthnet_$timestamp.sql"

    log_info "Creating database backup..."

    local db_name=$(grep "^DB_DATABASE=" "$ENV_FILE" | cut -d'=' -f2 | head -1)
    local db_user=$(grep "^DB_USERNAME=" "$ENV_FILE" | cut -d'=' -f2 | head -1)
    local db_pass=$(grep "^DB_PASSWORD=" "$ENV_FILE" | cut -d'=' -f2 | head -1)

    local compose_cmd=$(build_compose_cmd)
    eval "$compose_cmd exec -T mysql mysqldump -u$db_user -p$db_pass $db_name" > "$backup_file"

    if [ $? -eq 0 ]; then
        log_success "Backup created: $backup_file"
    else
        log_error "Backup failed!"
        rm -f "$backup_file"
        exit 1
    fi
}

# Database restore
restore_database() {
    local file="$1"

    if [ -z "$file" ]; then
        log_error "Usage: $0 restore 'backups/filename.sql'"
        exit 1
    fi

    if [ ! -f "$PROJECT_DIR/$file" ]; then
        log_error "Backup file not found: $file"
        exit 1
    fi

    log_warning "This will overwrite the current database!"
    read -p "Are you sure? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_info "Restore cancelled"
        return 0
    fi

    local db_name=$(grep "^DB_DATABASE=" "$ENV_FILE" | cut -d'=' -f2 | head -1)
    local db_user=$(grep "^DB_USERNAME=" "$ENV_FILE" | cut -d'=' -f2 | head -1)
    local db_pass=$(grep "^DB_PASSWORD=" "$ENV_FILE" | cut -d'=' -f2 | head -1)

    local compose_cmd=$(build_compose_cmd)
    cat "$PROJECT_DIR/$file" | eval "$compose_cmd exec -T mysql mysql -u$db_user -p$db_pass $db_name"

    if [ $? -eq 0 ]; then
        log_success "Database restored from: $file"
    else
        log_error "Restore failed!"
        exit 1
    fi
}

# Check environment
check_env() {
    if [ ! -f "$ENV_FILE" ]; then
        log_error ".env file not found!"
        echo "Run '$0 setup-dev' or '$0 setup-prod' first"
        exit 1
    fi
}

# Show help
show_help() {
    echo "DepthNet Docker Manager"
    echo ""

    detect_environment
    detect_sandbox_mode
    if [ -f "$ENV_FILE" ]; then
        echo "Current environment: $DETECTED_ENV ($ENV_TYPE)"
        echo "Using: $COMPOSE_FILE"
        echo "Docker user: UID:$DOCKER_UID, GID:$DOCKER_GID"
        if [ "$SANDBOX_MODE" = "true" ]; then
            echo "Sandbox: Enabled"
        else
            echo "Sandbox: Disabled (lightweight mode)"
        fi
    else
        echo "No .env file found - run setup first"
    fi

    echo "=========================="
    echo ""
    echo "Environment setup:"
    echo "  $0 setup-dev          Setup development environment (no sandbox)"
    echo "  $0 setup-dev-full     Setup development environment (with sandbox)"
    echo "  $0 setup-prod         Setup production environment (no sandbox)"
    echo "  $0 setup-prod-full    Setup production environment (with sandbox)"
    echo ""
    echo "Container management:"
    echo "  $0 start              Start containers (build if needed)"
    echo "  $0 stop               Stop containers"
    echo "  $0 restart            Restart containers"
    echo "  $0 up                 Start in foreground"
    echo "  $0 status             Show container status"
    echo "  $0 build              Build images without starting"
    echo ""
    echo "Sandbox management:"
    echo "  $0 sandbox-toggle     Toggle sandbox on/off:"
    echo "    enable                Enable sandbox support"
    echo "    disable               Disable sandbox support"
    echo "  $0 sandbox             Open shell in sandbox manager"
    echo "  $0 sandbox [name]      Open shell in specific sandbox container"
    echo "  $0 sandbox-control     Manage sandbox containers:"
    echo "    list                   List all sandboxes"
    echo "    start [name]           Start sandbox"
    echo "    stop [name]            Stop sandbox"  
    echo "    restart [name]         Restart sandbox"
    echo "    destroy [name]         Destroy sandbox"
    echo "    cleanup                Destroy all sandboxes"
    echo ""
    echo "Development tools:"
    echo "  $0 shell              Open shell as depthnet user"
    echo "  $0 rootshell          Open shell as root"
    echo "  $0 logs [service]     Show logs (default: app)"
    echo "  $0 logs all           Show all logs"
    echo ""
    echo "Laravel commands:"
    echo "  $0 artisan 'cmd'      Run artisan command"
    echo "  $0 composer 'cmd'     Run composer command"
    echo ""
    echo "Information:"
    echo "  $0 urls               Show application URLs"
    echo "  $0 ports              Show port resolution info"
    echo ""
    echo "Maintenance:"
    echo "  $0 fix-permissions    Fix file permissions"
    echo "  $0 clean              Clean up containers"
    echo "  $0 reset              Complete reset (containers, volumes, images)"
    echo "  $0 prune              Reset (containers, volumes, images), keep volumes"
    echo "  $0 backup             Create database backup to backups/"
    echo "  $0 restore 'file'     Restore database from backup file"
    echo ""
}

# Main function
main() {
    cd "$PROJECT_DIR"

    check_sudo
    detect_docker_socket_gid
    detect_environment

    case "${1:-help}" in
        "setup-dev")
            setup_env "dev" "false"
            ;;
        "setup-dev-full")
            setup_env "dev" "true"
            ;;
        "setup-prod")
            setup_env "prod" "false"
            ;;
        "setup-prod-full")
            setup_env "prod" "true"
            ;;
        "setup")
            interactive_setup "" ""
            ;;
        "sandbox-toggle")
            toggle_sandbox "$2"
            ;;
        "start")
            check_env
            start_containers
            ;;
        "stop")
            check_env
            stop_containers
            ;;
        "restart")
            check_env
            restart_containers
            ;;
        "up")
            check_env
            start_foreground
            ;;
        "status")
            check_env
            show_status
            ;;
        "build")
            check_env
            build_containers
            ;;
        "logs")
            check_env
            show_logs "${2:-app}"
            ;;
        "shell")
            check_env
            open_shell "depthnet"
            ;;
        "rootshell")
            check_env
            open_shell "root"
            ;;
        "sandbox")
            check_env
            open_sandbox_shell "$2"
            ;;
        "sandbox-control")
            check_env
            sandbox_control "$2" "$3"
            ;;
        "artisan")
            check_env
            run_artisan "$2"
            ;;
        "composer")
            check_env
            run_composer "$2"
            ;;
        "urls")
            check_env
            show_urls
            ;;
        "ports")
            check_env
            show_port_info
            ;;
        "fix-permissions")
            fix_permissions
            ;;
        "clean")
            cleanup
            ;;
        "reset")
            reset_project
            ;;
        "prune")
            prune_project
            ;;
        "backup")
            check_env
            backup_database
            ;;
        "restore")
            check_env
            restore_database "$2"
            ;;
        "help"|*)
            show_help
            ;;
    esac
}

main "$@"
