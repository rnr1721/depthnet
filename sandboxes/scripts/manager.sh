#!/bin/bash

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATES_DIR="$(dirname "$SCRIPT_DIR")/templates"
SANDBOX_PREFIX="${SANDBOX_PREFIX:-depthnet-sandbox}"
SANDBOX_NETWORK="${SANDBOX_NETWORK:-depthnet_depthnet}"
HOST_GID="${HOST_GID:-1000}"
HOST_UID="${HOST_UID:-1000}"


SANDBOX_MEMORY="${SANDBOX_MEMORY:-512m}"
SANDBOX_CPUS="${SANDBOX_CPUS:-1.0}"
SANDBOX_TMPFS_SIZE="${SANDBOX_TMPFS_SIZE:-100m}"
SANDBOX_SECURITY_MODE="${SANDBOX_SECURITY_MODE:-permissive}"
SANDBOX_DROP_CAPS="${SANDBOX_DROP_CAPS:-false}"
SANDBOX_DEFAULT_TIMEOUT="${SANDBOX_DEFAULT_TIMEOUT:-30}"
SANDBOX_DEFAULT_USER="${SANDBOX_DEFAULT_USER:-sandbox-user}"
SANDBOX_DEFAULT_SHELL="${SANDBOX_DEFAULT_SHELL:-bash}"
SANDBOX_READONLY_ROOT="${SANDBOX_READONLY_ROOT:-false}"
SANDBOX_ENABLE_PRIVILEGED="${SANDBOX_ENABLE_PRIVILEGED:-false}"
SANDBOX_ENABLE_SYS_ADMIN="${SANDBOX_ENABLE_SYS_ADMIN:-false}"

log_info() { echo "Info:  $1"; }
log_success() { echo "Success: $1"; }
log_error() { echo "Error: $1" >&2; }
log_debug() { echo "Debug: $1" >&2; }
log_warning() { echo "Warning: $1" >&2; }

# Validate configuration
if [[ ! "$SANDBOX_MEMORY" =~ ^[0-9]+[mMgG]?$ ]]; then
    log_warning "Invalid SANDBOX_MEMORY format: $SANDBOX_MEMORY, using default"
    SANDBOX_MEMORY="512m"
fi

if [[ ! "$SANDBOX_CPUS" =~ ^[0-9]+\.?[0-9]*$ ]]; then
    log_warning "Invalid SANDBOX_CPUS format: $SANDBOX_CPUS, using default"
    SANDBOX_CPUS="1.0"
fi

# Create sandbox with enhanced diagnostics
create_sandbox() {
    local type="${1:-ubuntu-full}"
    local name="${2:-$(date +%s)}"
    local ports="${3:-}"
    local container_name="${SANDBOX_PREFIX}-${name}"

    log_info "Creating sandbox: $container_name (type: $type)"

    # Container exists?
    if docker ps -a --format '{{.Names}}' | grep -q "^${container_name}$"; then
        log_error "Sandbox $container_name already exists"
        return 1
    fi

    # Check if network exists
    if ! docker network ls --format '{{.Name}}' | grep -q "^${SANDBOX_NETWORK}$"; then
        log_error "Network $SANDBOX_NETWORK not found!"
        log_info "Available networks:"
        docker network ls
        return 1
    fi

    # Build image if needed
    local image_name="sandbox-${type}"
    if ! docker images --format '{{.Repository}}' | grep -q "^${image_name}$"; then
        log_info "Building sandbox image: $image_name"
        
        # Check if dockerfile exists
        if [ ! -f "$TEMPLATES_DIR/${type}.dockerfile" ]; then
            log_error "Dockerfile not found: $TEMPLATES_DIR/${type}.dockerfile"
            log_info "Available templates:"
            show_templates
            return 1
        fi
        
        if ! docker build -f "$TEMPLATES_DIR/${type}.dockerfile" \
            --build-arg HOST_UID=${HOST_UID} \
            --build-arg HOST_GID=${HOST_GID} \
            -t "$image_name" "$TEMPLATES_DIR"; then
            log_error "Failed to build image $image_name"
            return 1
        fi
    fi

    # Setup paths
    local host_shared_path="${HOST_SHARED_PATH}"
    if [ -z "$host_shared_path" ]; then
        log_error "HOST_SHARED_PATH environment variable not set!"
        return 1
    fi

    local sandbox_host_dir="${host_shared_path}/sandbox-${name}"
    local sandbox_container_dir="/home/sandbox-user"

    # Setup port mapping
    local port_args=""
    if [ -n "$ports" ]; then
        # Custom ports specified - check availability
        IFS=',' read -ra PORT_ARRAY <<< "$ports"
        for port in "${PORT_ARRAY[@]}"; do
            port=$(echo "$port" | tr -d ' ')  # Remove whitespace
            
            # Check if port is in use on HOST
            if docker exec "$(hostname)" netstat -tuln 2>/dev/null | grep -q ":${port}\s" || \
            docker exec "$(hostname)" ss -tuln 2>/dev/null | grep -q ":${port}\s"; then
                log_error "Port $port is already in use on host!"
                return 1
            fi
            
            port_args="$port_args -p $port:$port"
        done
        log_info "Port mapping: $ports"
    else
        # NO default ports - create without port mapping
        log_info "No port mapping (internal network only)"
    fi

    log_debug "Host path: $sandbox_host_dir -> Container path: $sandbox_container_dir"

    # Create container with port mapping

    local security_args=""
    if [ "$SANDBOX_SECURITY_MODE" = "strict" ] || [ "$SANDBOX_DROP_CAPS" = "true" ]; then
        security_args="--cap-drop=ALL --cap-add=DAC_OVERRIDE --cap-add=SETUID --cap-add=SETGID"
        log_info "Security mode: strict"
    else
        log_info "Security mode: permissive"
    fi

    # Add privileged mode if enabled
    if [ "$SANDBOX_ENABLE_PRIVILEGED" = "true" ]; then
        security_args="$security_args --privileged"
        log_warning "Running in privileged mode!"
    fi

    # Add sys_admin capability if needed
    if [ "$SANDBOX_ENABLE_SYS_ADMIN" = "true" ]; then
        security_args="$security_args --cap-add=SYS_ADMIN"
    fi

    # Build readonly root filesystem
    local readonly_args=""
    if [ "$SANDBOX_READONLY_ROOT" = "true" ]; then
        readonly_args="--read-only"
    fi

    # Create container with configurable resources
    if ! docker run -d \
        --name "$container_name" \
        --network "$SANDBOX_NETWORK" \
        --user "${HOST_UID}:${HOST_GID}" \
        --memory="$SANDBOX_MEMORY" \
        --cpus="$SANDBOX_CPUS" \
        $security_args \
        $readonly_args \
        --tmpfs /tmp:noexec,nosuid,size="$SANDBOX_TMPFS_SIZE" \
        $port_args \
        -v "${sandbox_host_dir}:${sandbox_container_dir}" \
        "$image_name" \
        sleep infinity; then

        log_error "Failed to create container $container_name"
        return 1
    fi

    # Fix permissions using app container
    log_info "Fixing shared directory permissions..."
    local app_container=$(docker ps --filter "name=.*app" --format "{{.Names}}" | head -1)
    if [ -n "$app_container" ]; then
        docker exec --user root "$app_container" bash -c "
            # Wait a moment for volume to be mounted
            sleep 1
            if [ -d '/var/www/html/shared/sandbox-${name}' ]; then
                chown -R ${HOST_UID}:${HOST_GID} '/var/www/html/shared/sandbox-${name}'
                chmod 755 '/var/www/html/shared/sandbox-${name}'
            fi
        " 2>/dev/null || log_warning "Could not fix permissions via app container"
    fi

    # Initialize shared directory with proper permissions
    docker exec --user root "$container_name" bash -c "
        chown ${HOST_UID}:${HOST_GID} /home/sandbox-user
        chmod 755 /home/sandbox-user
    " 2>/dev/null || log_warning "Could not fix container permissions"

    # Initialize with README
    docker exec "$container_name" bash -c "
        echo 'Sandbox: $name' > /home/sandbox-user/README.txt &&
        echo 'Created: \$(date)' >> /home/sandbox-user/README.txt &&
        if [ -n '$ports' ]; then
            echo 'Available ports: $ports' >> /home/sandbox-user/README.txt &&
            echo 'Access via: http://localhost:[PORT]' >> /home/sandbox-user/README.txt
        else
            echo 'No external ports mapped' >> /home/sandbox-user/README.txt &&
            echo 'Access via internal network only' >> /home/sandbox-user/README.txt
        fi
    " 2>/dev/null || true

    # Verify container is running
    sleep 2
    if ! docker ps --format '{{.Names}}' | grep -q "^${container_name}$"; then
        log_error "Container $container_name failed to start"
        log_info "Container logs:"
        docker logs "$container_name" 2>&1 || true
        return 1
    fi

    log_success "Sandbox $container_name created successfully"
    log_info "Shared directory: ./shared/sandbox-${name}"
    if [ -n "$ports" ]; then
        log_info "Web access: http://localhost:$ports"
    else
        log_info "Internal network access only"
    fi
    echo "$container_name"
}

# Start sandbox
start_sandbox() {
    local sandbox_name="$1"

    if [ -z "$sandbox_name" ]; then
        log_error "Usage: start_sandbox <sandbox_name>"
        return 1
    fi

    local container_name="${SANDBOX_PREFIX}-${sandbox_name}"

    log_info "Starting sandbox: $container_name"

    # Check if container exists
    if ! docker ps -a --format '{{.Names}}' | grep -q "^${container_name}$"; then
        log_error "Sandbox $container_name not found"
        return 1
    fi

    # Check if already running
    if docker ps --format '{{.Names}}' | grep -q "^${container_name}$"; then
        log_info "Sandbox $container_name is already running"
        return 0
    fi

    # Check if container has network issues (old network ID)
    local container_network_id=$(docker inspect "$container_name" --format '{{range .NetworkSettings.Networks}}{{.NetworkID}}{{end}}' 2>/dev/null)
    local current_network_id=$(docker network inspect "$SANDBOX_NETWORK" --format '{{.Id}}' 2>/dev/null)

    if [ -n "$container_network_id" ] && [ -n "$current_network_id" ] && [ "$container_network_id" != "$current_network_id" ]; then
        log_info "Network mismatch detected - reconnecting to current network..."
        log_info "Container network: ${container_network_id:0:12}..."
        log_info "Current network:   ${current_network_id:0:12}..."

        # Disconnect from old network (if still connected)
        docker network disconnect "$SANDBOX_NETWORK" "$container_name" 2>/dev/null || true

        # Connect to current network
        if docker network connect "$SANDBOX_NETWORK" "$container_name" 2>/dev/null; then
            log_success "Reconnected $container_name to current network"
        else
            log_warning "Failed to reconnect to network - will try to start anyway"
        fi
    fi
    
    # Start the container
    if docker start "$container_name" >/dev/null 2>&1; then
        log_success "🟢 Sandbox $container_name started"
    else
        log_error "Failed to start sandbox $container_name"

        # Get detailed error information
        local exit_code=$(docker inspect "$container_name" --format '{{.State.ExitCode}}' 2>/dev/null)
        local error_msg=$(docker inspect "$container_name" --format '{{.State.Error}}' 2>/dev/null)

        if [ -n "$error_msg" ] && [ "$error_msg" != "" ] && [ "$error_msg" != "<no value>" ]; then
            log_error "Error details: $error_msg"

            # If it's still a network error, try manual fix
            if [[ "$error_msg" == *"network"*"not found"* ]]; then
                log_info "Attempting network recovery..."

                # Force disconnect and reconnect
                docker network disconnect "$SANDBOX_NETWORK" "$container_name" 2>/dev/null || true
                sleep 1

                if docker network connect "$SANDBOX_NETWORK" "$container_name" 2>/dev/null; then
                    log_info "Network reconnected, trying start again..."
                    if docker start "$container_name" >/dev/null 2>&1; then
                        log_success "🟢 Sandbox $container_name started after network fix"
                        return 0
                    fi
                fi

                log_error "Network recovery failed. Consider recreating the sandbox:"
                log_error "  sm destroy $sandbox_name && sm create ubuntu-full $sandbox_name"
            fi
        fi

        log_info "Container logs:"
        docker logs "$container_name" 2>&1 | tail -10 || true
        return 1
    fi
}

# Stop sandbox
stop_sandbox() {
    local sandbox_name="$1"
    local timeout="${2:-10}"

    if [ -z "$sandbox_name" ]; then
        log_error "Usage: stop_sandbox <sandbox_name> [timeout]"
        return 1
    fi

    local container_name="${SANDBOX_PREFIX}-${sandbox_name}"
    
    # Check if trying to stop current container
    local current_container=$(get_current_container 2>/dev/null)
    if [ -n "$current_container" ] && [[ "$container_name" == "$current_container"* ]] || [[ "$current_container" == "$container_name"* ]]; then
        log_error "Cannot stop current container: $current_container"
        log_error "This would terminate the sandbox manager itself!"
        return 1
    fi

    log_info "Stopping sandbox: $container_name (timeout: ${timeout}s)"

    # Check if container exists
    if ! docker ps -a --format '{{.Names}}' | grep -q "^${container_name}$"; then
        log_error "Sandbox $container_name not found"
        return 1
    fi

    # Check if already stopped
    if ! docker ps --format '{{.Names}}' | grep -q "^${container_name}$"; then
        log_info "Sandbox $container_name is already stopped"
        return 0
    fi

    # Stop the container with timeout
    if docker stop -t "$timeout" "$container_name" >/dev/null 2>&1; then
        log_success "🔴 Sandbox $container_name stopped"
    else
        log_error "Failed to stop sandbox $container_name"
        return 1
    fi
}

# Run command in sandbox
exec_command() {
    local sandbox_name="$1"
    local command="$2"
    local user="${3:-sandbox-user}"
    local timeout="${4:-$SANDBOX_DEFAULT_TIMEOUT}"

    if [ -z "$sandbox_name" ] || [ -z "$command" ]; then
        log_error "Usage: exec_command <sandbox_name> <command> [user] [timeout]"
        return 1
    fi

    local container_name="${SANDBOX_PREFIX}-${sandbox_name}"

    ## log_info "Executing in $container_name as $user: $command"

    # Container exist and running?
    if ! docker ps --format '{{.Names}}' | grep -q "^${container_name}$"; then
        log_error "Sandbox $container_name not found or not running"
        
        # Check if container exists but stopped
        if docker ps -a --format '{{.Names}}' | grep -q "^${container_name}$"; then
            log_info "Container exists but is stopped. Logs:"
            docker logs "$container_name" 2>&1 | tail -10 || true
        fi
        return 1
    fi

    # Run command with timeout
    timeout "$timeout" docker exec --user "$user" "$container_name" bash -c "$command"
    local exit_code=$?

    if [ $exit_code -eq 124 ]; then
        log_error "Command timed out after ${timeout}s"
        return 124
    fi

    return $exit_code
}

# Dive into sandbox (interactive shell)
dive_sandbox() {
    local sandbox_name="$1"
    local user="${2:-$SANDBOX_DEFAULT_USER}"
    local shell="${3:-$SANDBOX_DEFAULT_SHELL}"

    if [ -z "$sandbox_name" ]; then
        log_error "Usage: dive_sandbox <sandbox_name> [user] [shell]"
        return 1
    fi

    local container_name="${SANDBOX_PREFIX}-${sandbox_name}"

    log_info "Diving into $container_name as $user (shell: $shell)"

    # Container exist and running?
    if ! docker ps --format '{{.Names}}' | grep -q "^${container_name}$"; then
        log_error "Sandbox $container_name not found or not running"

        # Check if container exists but stopped
        if docker ps -a --format '{{.Names}}' | grep -q "^${container_name}$"; then
            log_info "Container exists but is stopped. Starting it first..."
            if start_sandbox "$sandbox_name"; then
                log_info "Container started. Diving in..."
            else
                return 1
            fi
        else
            return 1
        fi
    fi
    
    # Check if shell exists in container
    if ! docker exec "$container_name" which "$shell" >/dev/null 2>&1; then
        log_warning "Shell '$shell' not found, falling back to 'sh'"
        shell="sh"
    fi

    # Dive into interactive shell
    log_info "Entering interactive session. Type 'exit' to return."
    docker exec -it --user "$user" "$container_name" "$shell"

    local exit_code=$?
    if [ $exit_code -eq 0 ]; then
        log_success "Exited from $container_name"
    else
        log_info "Exited from $container_name with code $exit_code"
    fi

    return $exit_code
}

# Reset sandbox to start state
reset_sandbox() {
    local sandbox_name="$1"
    local type="${2:-ubuntu-full}"

    if [ -z "$sandbox_name" ]; then
        log_error "Usage: reset_sandbox <sandbox_name> [type]"
        return 1
    fi

    log_info "Resetting sandbox: $sandbox_name"

    # Remove old container
    destroy_sandbox "$sandbox_name"

    # Create new with same name
    create_sandbox "$type" "$sandbox_name" >/dev/null

    log_success "Sandbox $sandbox_name reset successfully"
}

# Rebuild sandbox image
rebuild_image() {
    local type="${1:-ubuntu-full}"
    local force="${2:-false}"
    local image_name="sandbox-${type}"

    log_info "Rebuilding image: $image_name"

    # Check if dockerfile exists
    if [ ! -f "$TEMPLATES_DIR/${type}.dockerfile" ]; then
        log_error "Dockerfile not found: $TEMPLATES_DIR/${type}.dockerfile"
        return 1
    fi

    # Remove old image if exists
    if docker images --format '{{.Repository}}' | grep -q "^${image_name}$"; then
        log_info "Removing old image: $image_name"
        docker rmi "$image_name" 2>/dev/null || {
            if [ "$force" = "force" ] || [ "$force" = "-f" ]; then
                log_info "Force removing image..."
                docker rmi -f "$image_name" || {
                    log_error "Failed to remove image $image_name"
                    return 1
                }
            else
                log_error "Cannot remove image $image_name (containers may be using it)"
                log_info "Use 'rebuild $type force' to force removal"
                return 1
            fi
        }
    fi

    # Build new image
    if docker build -f "$TEMPLATES_DIR/${type}.dockerfile" \
        --build-arg HOST_UID=${HOST_UID} \
        --build-arg HOST_GID=${HOST_GID} \
        --no-cache \
        -t "$image_name" "$TEMPLATES_DIR"; then
        log_success "Image $image_name rebuilt successfully"
    else
        log_error "Failed to rebuild image $image_name"
        return 1
    fi
}

# Remove sandbox image
remove_image() {
    local type="${1}"
    local force="${2:-false}"

    if [ -z "$type" ]; then
        log_error "Usage: remove_image <type> [force]"
        return 1
    fi

    local image_name="sandbox-${type}"

    # Protection: don't remove manager images
    if [[ "$image_name" == *"manager"* ]] || [[ "$image_name" == *"app"* ]]; then
        log_error "Cannot remove protected image: $image_name"
        return 1
    fi

    log_info "Removing image: $image_name"

    # Check if image exists
    if ! docker images --format '{{.Repository}}' | grep -q "^${image_name}$"; then
        log_error "Image $image_name not found"
        return 1
    fi

    # Check if containers are using this image
    local containers_using=$(docker ps -a --filter "ancestor=$image_name" --format '{{.Names}}' 2>/dev/null)
    if [ -n "$containers_using" ]; then
        log_error "Cannot remove image $image_name - containers are using it:"
        echo "$containers_using"
        if [ "$force" = "force" ] || [ "$force" = "-f" ]; then
            log_info "Force removing containers first..."
            docker ps -aq --filter "ancestor=$image_name" | xargs -r docker rm -f
        else
            log_info "Use 'rmi $type force' to force removal"
            return 1
        fi
    fi

    # Remove the image
    if docker rmi "$image_name" 2>/dev/null; then
        log_success "Image $image_name removed successfully"
    else
        if [ "$force" = "force" ] || [ "$force" = "-f" ]; then
            log_info "Force removing image..."
            docker rmi -f "$image_name" || {
                log_error "Failed to force remove image $image_name"
                return 1
            }
            log_success "Image $image_name force removed"
        else
            log_error "Failed to remove image $image_name"
            return 1
        fi
    fi
}

# Purge sandbox image completely (force rebuild on next create)
purge_image() {
    local type="${1}"

    if [ -z "$type" ]; then
        log_error "Usage: purge_image <type>"
        return 1
    fi

    local image_name="sandbox-${type}"

    # Protection: don't purge manager images
    if [[ "$image_name" == *"manager"* ]] || [[ "$image_name" == *"app"* ]]; then
        log_error "Cannot purge protected image: $image_name"
        return 1
    fi

    log_info "Purging image completely: $image_name"
    log_warning "This will force rebuild on next create!"

    # Stop and remove ALL containers using this image
    local containers_using=$(docker ps -aq --filter "ancestor=$image_name" 2>/dev/null)
    if [ -n "$containers_using" ]; then
        log_info "Force stopping and removing containers using $image_name..."
        echo "$containers_using" | xargs -r docker rm -f
        log_success "Removed $(echo "$containers_using" | wc -l) containers"
    fi

    # Remove the image with extreme prejudice
    if docker images --format '{{.Repository}}' | grep -q "^${image_name}$"; then
        log_info "Force removing image $image_name..."
        if docker rmi -f "$image_name" 2>/dev/null; then
            log_success "Image $image_name purged successfully"
        else
            log_error "Failed to purge image $image_name"
            return 1
        fi
    else
        log_info "Image $image_name not found (already purged)"
    fi

    # Also clean up any dangling images from this template
    log_info "Cleaning up dangling images..."
    docker image prune -f >/dev/null 2>&1 || true

    log_success "Image $image_name completely purged!"
    log_info "Next 'create $type' will rebuild from scratch"
}

# Delete the sandbox
destroy_sandbox() {
    local sandbox_name="$1"
    local keep_files="${2:-false}"

    if [ -z "$sandbox_name" ]; then
        log_error "Usage: destroy_sandbox [keep_files] <sandbox_name>"
        return 1
    fi

    local container_name="${SANDBOX_PREFIX}-${sandbox_name}"

    # Check if trying to destroy current container
    local current_container=$(get_current_container 2>/dev/null)
    if [ -n "$current_container" ] && [[ "$container_name" == "$current_container"* ]] || [[ "$current_container" == "$container_name"* ]]; then
        log_error "Cannot destroy current container: $current_container"
        log_error "This would terminate the sandbox manager itself!"
        return 1
    fi

    log_info "Destroying sandbox: $container_name"
    
    if docker ps -a --format '{{.Names}}' | grep -q "^${container_name}$"; then
        docker rm -f "$container_name" >/dev/null 2>&1
        log_success "Sandbox $container_name destroyed"
    else
        log_info "Sandbox $container_name not found"
    fi

}

# Show shared directories
show_shared_dirs() {
    log_info "Shared directories on host:"

    if [ -d "/host_shared" ]; then
        ls -la /host_shared/ | grep sandbox- || log_info "No sandbox directories found"
        echo ""
        log_info "Host path: ./shared/"
        log_info "From host system: cd \$(pwd)/shared"
    else
        log_error "Host shared directory not found"
    fi
}

# Show available templates
show_templates() {
    log_info "Available sandbox templates:"

    if [ -d "$TEMPLATES_DIR" ]; then
        echo "Templates directory: $TEMPLATES_DIR"
        echo ""

        # List all .dockerfile files
        if ! ls "$TEMPLATES_DIR"/*.dockerfile >/dev/null 2>&1; then
            log_error "No .dockerfile templates found in $TEMPLATES_DIR"
            return 1
        fi
        local templates=$(ls "$TEMPLATES_DIR"/*.dockerfile | sed 's/.*\///' | sed 's/\.dockerfile$//')

        if [ -n "$templates" ]; then
            echo "Available types:"
            for template in $templates; do
                echo "  - $template"
                
                # Show first few lines of dockerfile for description
                local dockerfile="$TEMPLATES_DIR/${template}.dockerfile"
                local description=$(head -5 "$dockerfile" | grep -E "^#.*" | head -1 | sed 's/^# *//')
                if [ -n "$description" ]; then
                    echo "    $description"
                fi
            done
        else
            log_error "No .dockerfile templates found"
        fi
    else
        log_error "Templates directory not found: $TEMPLATES_DIR"
    fi
}

# Show current container info
show_current_container() {
    local current_container=$(get_current_container)
    if [ -n "$current_container" ]; then
        log_info "Running inside container: $current_container"
        echo "$current_container"
    else
        log_info "Not running inside a container (or unable to detect)"
        return 1
    fi
}

# List sandboxes (running and stopped)
list_sandboxes() {
    local show_all="${1:-false}"

    if [ "$show_all" = "all" ] || [ "$show_all" = "-a" ]; then
        log_info "All sandboxes (running and stopped):"
        docker ps -a --filter "name=${SANDBOX_PREFIX}-" --format "table {{.Names}}\t{{.Status}}\t{{.Image}}"
    else
        log_info "Active sandboxes:"
        docker ps --filter "name=${SANDBOX_PREFIX}-" --format "table {{.Names}}\t{{.Status}}\t{{.Image}}"
    fi
}

# Clear all sandboxes (except protected ones) with cleanup
cleanup_all() {
    log_info "Cleaning up all sandboxes..."

    # Get current container for protection
    local current_container=$(get_current_container 2>/dev/null)

    # Get list of containers to remove
    local containers_to_remove=$(docker ps -aq --filter "name=${SANDBOX_PREFIX}-" 2>/dev/null)

    if [ -z "$containers_to_remove" ]; then
        log_info "No sandboxes found to cleanup"
        return 0
    fi

    local removed_count=0
    local protected_count=0

    for container_id in $containers_to_remove; do
        # Get container name
        local container_name=$(docker ps -a --format '{{.Names}}' --filter "id=$container_id" 2>/dev/null)

        # Skip if we can't get the name
        if [ -z "$container_name" ]; then
            continue
        fi

        # Check if this is a protected container
        if [ -n "$current_container" ] && [[ "$container_name" == "$current_container"* ]] || [[ "$current_container" == "$container_name"* ]]; then
            log_info "Skipping protected container: $container_name"
            protected_count=$((protected_count + 1))
            continue
        fi

        # Also skip manager containers
        if [[ "$container_name" == *"sandbox-manager"* ]]; then
            log_info "Skipping manager container: $container_name"
            protected_count=$((protected_count + 1))
            continue
        fi

        # Remove the container
        if docker rm -f "$container_id" >/dev/null 2>&1; then
            log_info "Removed sandbox: $container_name"
            removed_count=$((removed_count + 1))
        else
            log_error "Failed to remove sandbox: $container_name"
        fi
    done
    
    # Post-cleanup network verification
    log_info "Verifying network connectivity..."
    if docker network inspect "$SANDBOX_NETWORK" >/dev/null 2>&1; then
        log_success "Network $SANDBOX_NETWORK is healthy"
    else
        log_error "Network $SANDBOX_NETWORK may have issues"
    fi

    if [ $protected_count -gt 0 ]; then
        log_success "Cleanup completed: $removed_count removed, $protected_count protected"
    else
        log_success "All sandboxes cleaned up: $removed_count removed"
    fi
}

# Get current container name (if running inside container) - optimized
get_current_container() {
    # Attempt 1: Check hostname if it matches container pattern
    local hostname=$(hostname)
    if [[ "$hostname" =~ ^[0-9a-f]{12}$ ]] || [[ "$hostname" =~ depthnet ]]; then
        echo "$hostname"
        return 0
    fi

    # Attempt 2: Check cgroup for container ID
    if [ -f /proc/1/cgroup ]; then
        local container_id=$(cat /proc/1/cgroup | grep docker | head -1 | sed 's/.*\///' | cut -c1-12)
        if [ -n "$container_id" ]; then
            # Get container name from ID
            local container_name=$(docker ps --format '{{.Names}}' --filter "id=$container_id" 2>/dev/null | head -1)
            if [ -n "$container_name" ]; then
                echo "$container_name"
                return 0
            fi
        fi
    fi

    # Attempt 3: Check environment variables
    if [ -n "$HOSTNAME" ] && docker ps --format '{{.Names}}' | grep -q "^$HOSTNAME$" 2>/dev/null; then
        echo "$HOSTNAME"
        return 0
    fi

    return 1
}

# Diagnostic function
diagnose_system() {
    log_info "Running system diagnostics..."

    echo "=== Docker Status ==="
    docker version || log_error "Docker version failed"
    echo ""

    echo "=== Docker Info ==="
    docker info | head -15 || log_error "Docker info failed"
    echo ""

    echo "=== Networks ==="
    docker network ls
    echo ""

    echo "=== Sandbox Images ==="
    docker images --filter "reference=sandbox-*"
    echo ""

    echo "=== All Containers (including stopped) ==="
    docker ps -a --filter "name=${SANDBOX_PREFIX}-"
    echo ""

    echo "=== Current Container ==="
    show_current_container
    echo ""

    echo "=== Templates Directory ==="
    ls -la "$TEMPLATES_DIR"/ || log_error "Templates directory not accessible"
    echo ""

    echo "=== Network Details ==="
    docker network inspect "$SANDBOX_NETWORK" 2>/dev/null || log_error "Network $SANDBOX_NETWORK not found"
}

# Show current configuration
show_config() {
    log_info "Current Sandbox Manager Configuration:"
    echo ""
    echo "=== Resource Limits ==="
    echo "Memory: $SANDBOX_MEMORY"
    echo "CPUs: $SANDBOX_CPUS"
    echo "Tmpfs Size: $SANDBOX_TMPFS_SIZE"
    echo ""
    echo "=== Security Settings ==="
    echo "Security Mode: $SANDBOX_SECURITY_MODE"
    echo "Drop Capabilities: $SANDBOX_DROP_CAPS"
    echo "Readonly Root: $SANDBOX_READONLY_ROOT"
    echo "Privileged Mode: $SANDBOX_ENABLE_PRIVILEGED"
    echo "SysAdmin Cap: $SANDBOX_ENABLE_SYS_ADMIN"
    echo ""
    echo "=== Defaults ==="
    echo "Default Timeout: $SANDBOX_DEFAULT_TIMEOUT"
    echo "Default User: $SANDBOX_DEFAULT_USER"
    echo "Default Shell: $SANDBOX_DEFAULT_SHELL"
    echo ""
    echo "=== Network & Naming ==="
    echo "Network: $SANDBOX_NETWORK"
    echo "Prefix: $SANDBOX_PREFIX"
}

main() {
    case "${1:-help}" in
        "create")
            create_sandbox "$2" "$3" "$4"
            ;;
        "start")
            start_sandbox "$2"
            ;;
        "stop")
            stop_sandbox "$2" "$3"
            ;;
        "exec")
            exec_command "$2" "$3" "$4" "$5"
            ;;
        "dive")
            dive_sandbox "$2" "$3" "$4"
            ;;
        "reset")
            reset_sandbox "$2" "$3"
            ;;
        "rebuild")
            rebuild_image "$2" "$3"
            ;;
        "destroy")
            destroy_sandbox "$2" "$3"
            ;;
        "list")
            list_sandboxes "$2"
            ;;
        "shared")
            show_shared_dirs
            ;;
        "cleanup")
            cleanup_all
            ;;
        "current")
            show_current_container
            ;;
        "diagnose")
            diagnose_system
            ;;
        "config")
            show_config
            ;;
        "templates")
            show_templates
            ;;
        "rmi")
            remove_image "$2" "$3"
            ;;
        "purge")
            purge_image "$2"
            ;;
        "help"|*)
            echo "Sandbox Manager Commands:"
            echo "  create [type] [name] [ports]   - Create new sandbox"
            echo "  start <name>                   - Start stopped sandbox"
            echo "  stop <name> [timeout]          - Stop running sandbox"
            echo "  exec <name> <command> [user]   - Execute command in sandbox"
            echo "  dive <name> [user] [shell]     - Interactive shell into sandbox"
            echo "  reset <name> [type]            - Reset sandbox to clean state"
            echo "  rebuild [type] [force]         - Rebuild sandbox image"
            echo "  destroy <name>                 - Remove sandbox completely"
            echo "  list [all]                     - List sandboxes (add 'all' for stopped too)"
            echo "  shared                         - Show shared directories"
            echo "  cleanup                        - Remove all sandboxes"
            echo "  current                        - Show current container name"
            echo "  diagnose                       - Run system diagnostics"
            echo "  config                         - Show current configuration"
            echo "  templates                      - Show available templates"
            echo "  rmi <type> [force]             - Remove sandbox image"
            echo "  purge <type>                   - Completely purge image (force rebuild)"
            echo ""
            echo "Examples:"
            echo "  $0 create ubuntu-full ai-demo"
            echo "  $0 create ubuntu-full web-app 3000,8080"
            echo "  $0 exec ai-demo 'python3 -m http.server 8000'"
            echo "  $0 rebuild ubuntu-full"
            echo "  $0 rebuild ubuntu-full force"
            echo "  $0 start ai-demo"
            echo "  $0 list all"
            echo "  $0 templates"
            echo "  $0 rmi ubuntu-full"
            echo "  $0 rmi ubuntu-full force"
            echo ""
            echo "Port access:"
            echo "  Default ports: 3000,5000,8000,8080,9000"
            echo "  Access: http://localhost:[PORT]"
            echo "  Custom: create [type] [name] port1,port2,port3"
            ;;
    esac
}

main "$@"
