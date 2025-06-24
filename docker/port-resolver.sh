#!/bin/bash

# Docker Port Resolver Utility
# Determines the appropriate port based on environment configuration

set -e

ENV_FILE="${1:-.env}"

if [ ! -f "$ENV_FILE" ]; then
    echo "Error: Environment file '$ENV_FILE' not found" >&2
    exit 1
fi

# Source environment file safely
set -a  # automatically export all variables
source "$ENV_FILE"
set +a

# Function to determine port
get_port() {
    # Priority 1: Explicit APP_PORT
    if [ -n "$APP_PORT" ] && [ "$APP_PORT" != "0" ]; then
        echo "$APP_PORT"
        return 0
    fi

    # Priority 2: Extract port from APP_URL
    if [ -n "$APP_URL" ]; then
        # Remove protocol and path, then extract port
        extracted_port=$(echo "$APP_URL" | sed 's|^[^:]*://||' | sed 's|/.*$||' | grep -o ':[0-9]\+$' | cut -d: -f2 2>/dev/null || true)
        if [ -n "$extracted_port" ] && [ "$extracted_port" -gt 0 ] 2>/dev/null; then
            echo "$extracted_port"
            return 0
        fi
    fi

    # Priority 3: Default based on protocol
    if [ -n "$APP_URL" ]; then
        if echo "$APP_URL" | grep -q "^https://"; then
            echo "443"
            return 0
        elif echo "$APP_URL" | grep -q "^http://"; then
            echo "80"
            return 0
        fi
    fi

    # Fallback
    echo "8000"
}

# Function to get clean APP_URL (without port if default)
get_clean_url() {
    local port=$(get_port)
    
    if [ -n "$APP_URL" ]; then
        # Remove existing port from URL
        clean_url=$(echo "$APP_URL" | sed 's/:[0-9]\+$//')
        
        # Add port only if it's not default for the protocol
        if (echo "$APP_URL" | grep -q "^https://" && [ "$port" != "443" ]) || \
           (echo "$APP_URL" | grep -q "^http://" && [ "$port" != "80" ]); then
            echo "${clean_url}:${port}"
        else
            echo "$clean_url"
        fi
    else
        echo "http://localhost:${port}"
    fi
}

# Main logic based on command
case "${2:-port}" in
    "port")
        get_port
        ;;
    "url")
        get_clean_url
        ;;
    "info")
        port=$(get_port)
        url=$(get_clean_url)
        
        echo "=== Port Resolution Info ==="
        echo "Environment file: $ENV_FILE"
        echo "APP_URL: ${APP_URL:-'(not set)'}"
        echo "APP_PORT: ${APP_PORT:-'(not set)'}"
        echo ""
        echo "Resolved port: $port"
        echo "Final URL: $url"
        echo ""
        
        # Show resolution method
        if [ -n "$APP_PORT" ] && [ "$APP_PORT" != "0" ]; then
            echo "Method: Explicit APP_PORT"
        elif [ -n "$APP_URL" ] && echo "$APP_URL" | grep -q ":[0-9]\+"; then
            echo "Method: Extracted from APP_URL"
        elif [ -n "$APP_URL" ] && echo "$APP_URL" | grep -q "^https://"; then
            echo "Method: HTTPS default (443)"
        elif [ -n "$APP_URL" ] && echo "$APP_URL" | grep -q "^http://"; then
            echo "Method: HTTP default (80)"
        else
            echo "Method: Fallback default (8000)"
        fi
        ;;
    "export")
        port=$(get_port)
        url=$(get_clean_url)
        echo "export APP_PORT=$port"
        echo "export APP_URL_RESOLVED=$url"
        ;;
    *)
        echo "Usage: $0 [env_file] [command]"
        echo ""
        echo "Commands:"
        echo "  port     - Output resolved port number (default)"
        echo "  url      - Output clean URL with appropriate port"
        echo "  info     - Show detailed resolution information"
        echo "  export   - Output export statements for shell"
        echo ""
        echo "Examples:"
        echo "  $0 .env port"
        echo "  $0 .env info"
        echo "  eval \$($0 .env export)"
        exit 1
        ;;
esac
