#!/bin/bash

# Docker Manager Utility
# Manages DepthNet Docker environment with automatic port resolution

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$PROJECT_DIR/.env"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

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

    log_success "Environment configured (UID:$DOCKER_UID, GID:$DOCKER_GID)"
    
    if [ "$env_type" = "prod" ]; then
        log_warning "IMPORTANT: Edit .env file and set:"
        echo "   - APP_URL (your domain)"
        echo "   - DB_PASSWORD (strong password)"
        echo "   - DB_ROOT_PASSWORD (strong root password)"
    fi
    
    echo "You can now edit .env if needed, then run: $0 start"
}

# Build compose command
build_compose_cmd() {
    echo "DOCKER_UID=$DOCKER_UID DOCKER_GID=$DOCKER_GID docker compose -f $COMPOSE_FILE"
}

# Start containers
start_containers() {
    local resolved_port=$(get_resolved_port)
    
    log_info "Starting $ENV_TYPE environment..."
    log_info "Using port: $resolved_port (UID:$DOCKER_UID, GID:$DOCKER_GID)"
    
    local compose_cmd=$(build_compose_cmd)
    APP_PORT="$resolved_port" eval "$compose_cmd up -d --build"
    
    log_success "Containers started"
    show_urls
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
    
    log_info "Starting $ENV_TYPE environment in foreground..."
    log_info "Using port: $resolved_port (UID:$DOCKER_UID, GID:$DOCKER_GID)"
    
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
    local resolved_port=$(get_resolved_port)
    local resolved_url=$(get_resolved_url)
    
    echo ""
    echo " Application URLs:"
    echo "    App: $resolved_url"
    echo "    Port: $resolved_port"
    
    if [ "$DETECTED_ENV" != "production" ] && [ -f "$ENV_FILE" ]; then
        local pma_port=$(grep "^PMA_PORT=" "$ENV_FILE" | cut -d'=' -f2 | head -1 2>/dev/null || echo "8001")
        echo "   phpMyAdmin: http://localhost:$pma_port"
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

# Complete reset
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
    if [ -f "$ENV_FILE" ]; then
        echo "Current environment: $DETECTED_ENV ($ENV_TYPE)"
        echo "Using: $COMPOSE_FILE"
        echo "Docker user: UID:$DOCKER_UID, GID:$DOCKER_GID"
    else
        echo "No .env file found - run setup first"
    fi
    
    echo "=========================="
    echo ""
    echo "Environment setup:"
    echo "  $0 setup-dev          Setup development environment"
    echo "  $0 setup-prod         Setup production environment"
    echo ""
    echo "Container management:"
    echo "  $0 start              Start containers (build if needed)"
    echo "  $0 stop               Stop containers"
    echo "  $0 restart            Restart containers"
    echo "  $0 up                 Start in foreground"
    echo "  $0 status             Show container status"
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
    echo ""
}

# Main function
main() {
    cd "$PROJECT_DIR"
    
    check_sudo
    detect_environment
    
    case "${1:-help}" in
        "setup-dev")
            setup_env "dev"
            ;;
        "setup-prod")
            setup_env "prod"
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
        "help"|*)
            show_help
            ;;
    esac
}

main "$@"
