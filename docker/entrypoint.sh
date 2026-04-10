#!/bin/bash
set -e

# Get UID/GID from environment or use defaults
DOCKER_UID=${DOCKER_UID:-1000}
DOCKER_GID=${DOCKER_GID:-1000}
APP_DIR="${APP_DIR:-/var/www/html}"
APP_ENV=${APP_ENV:-local}

DB_HOST=${DB_HOST:-mysql}
DB_USERNAME=${DB_USERNAME:-depthnet}
DB_PASSWORD=${DB_PASSWORD:-secret}
DB_DATABASE=${DB_DATABASE:-depthnet}

DB_SSL_MODE=${DB_SSL_MODE:-DISABLED}
DB_SSL_CA=${DB_SSL_CA:-}
DB_SSL_CERT=${DB_SSL_CERT:-}
DB_SSL_KEY=${DB_SSL_KEY:-}

SSL_MODE=${SSL_MODE:-self-signed}

TZ=${TZ:-UTC}

cleanup() {
    echo "Shutting down gracefully..."
    if [ ! -z "$SUPERVISOR_PID" ]; then
        supervisorctl stop all 2>/dev/null || true
        kill $SUPERVISOR_PID 2>/dev/null || true
        wait $SUPERVISOR_PID 2>/dev/null || true
    fi
    exit 0
}
trap cleanup SIGTERM SIGINT

cd $APP_DIR

# Determine if we should run in production mode
is_production() {
    [ "$APP_ENV" = "production" ]
}

# Set timezone
if [ -n "$TZ" ] && [ -f "/usr/share/zoneinfo/$TZ" ]; then
    ln -sf "/usr/share/zoneinfo/$TZ" /etc/localtime
    echo "$TZ" > /etc/timezone
    # Set PHP timezone
    echo "date.timezone = $TZ" > /usr/local/etc/php/conf.d/timezone.ini
    echo "✓ Timezone set to $TZ"
fi

# Only set core.fileMode if not already disabled
if [ -d .git ] && ! git config --get core.fileMode 2>/dev/null | grep -q false; then
    git config core.fileMode false
fi

# Create .env if it doesn't exist
if [ ! -f .env ]; then
    echo "Error: No .env template found (.env.example.docker or .env.example.docker.prod)"
    echo "   Please copy it from .env.example.docker or .env.example.docker.prod file and set up"
    exit 1
fi

setup_env_from_url() {
    APP_URL_VALUE=$(grep "^APP_URL=" .env | cut -d'=' -f2)

    # Parse host and port from APP_URL
    APP_HOST=$(echo "$APP_URL_VALUE" | sed 's|https\?://||' | cut -d':' -f1 | cut -d'/' -f1)
    APP_PORT_PARSED=$(echo "$APP_URL_VALUE" | sed 's|https\?://||' | cut -d':' -f2 | cut -d'/' -f1)

    # If port not extracted (no port in URL) — clear it
    if [ "$APP_HOST" = "$APP_PORT_PARSED" ]; then
        APP_PORT_PARSED=""
    fi

    # SANCTUM_STATEFUL_DOMAINS — always sync with APP_URL
    SANCTUM_VALUE="$APP_HOST"
    [ -n "$APP_PORT_PARSED" ] && SANCTUM_VALUE="$APP_HOST:$APP_PORT_PARSED"
    if grep -q "^SANCTUM_STATEFUL_DOMAINS=" .env; then
        sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=$SANCTUM_VALUE|" .env
    else
        echo "SANCTUM_STATEFUL_DOMAINS=$SANCTUM_VALUE" >> .env
    fi
    echo "✓ SANCTUM_STATEFUL_DOMAINS set to $SANCTUM_VALUE"

    # SESSION_DOMAIN — always sync with APP_URL host
    if grep -q "^SESSION_DOMAIN=" .env; then
        sed -i "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=$APP_HOST|" .env
    else
        echo "SESSION_DOMAIN=$APP_HOST" >> .env
    fi
    echo "✓ SESSION_DOMAIN set to $APP_HOST"

    # SESSION_SECURE_COOKIE — set based on protocol
    if echo "$APP_URL_VALUE" | grep -q "^https://"; then
        if grep -q "^SESSION_SECURE_COOKIE=" .env; then
            sed -i "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" .env
        else
            echo "SESSION_SECURE_COOKIE=true" >> .env
        fi
        echo "✓ SESSION_SECURE_COOKIE set to true (HTTPS detected)"
    else
        if grep -q "^SESSION_SECURE_COOKIE=" .env; then
            sed -i "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|" .env
        fi
    fi

    # VITE_HMR_HOST — always sync with APP_URL host (dev only)
    if [ "$APP_ENV" != "production" ]; then
        if grep -q "^VITE_HMR_HOST=" .env; then
            sed -i "s|^VITE_HMR_HOST=.*|VITE_HMR_HOST=$APP_HOST|" .env
        else
            echo "VITE_HMR_HOST=$APP_HOST" >> .env
        fi
        echo "✓ VITE_HMR_HOST set to $APP_HOST"
    fi
}

setup_env_from_url

# Setup frontend dependencies
setup_frontend() {
    echo "Installing Node.js dependencies..."
    if is_production; then
        rm -f public/hot
    fi

    if ! npm install --include=dev; then
        echo "Failed to install npm dependencies"
        return 1
    fi

    # In development, ensure build directory exists with basic manifest for fallback
    if ! is_production; then
        echo "Development mode - preparing basic build directory..."
        mkdir -p public/build
        if [ ! -f public/build/manifest.json ]; then
            echo '{}' > public/build/manifest.json
        fi
    fi

    echo "Clear cache and make ziggy.js, npm run build etc..."
    if ! composer setup; then
        echo "Warning: composer setup failed, continuing..."
    fi

    if is_production; then
        composer prod-cache
    fi

}

setup_ssl() {
    SSL_DIR="/etc/nginx/ssl"
    SSL_CERT="$SSL_DIR/cert.pem"
    SSL_KEY="$SSL_DIR/key.pem"
    SSL_MODE=${SSL_MODE:-self-signed}

    case "$SSL_MODE" in
        "self-signed")
            mkdir -p "$SSL_DIR"
            if [ ! -f "$SSL_CERT" ] || ! openssl x509 -checkend 2592000 -noout -in "$SSL_CERT" 2>/dev/null; then
                echo "Generating self-signed SSL certificate..."
                openssl genrsa -traditional -out "$SSL_KEY" 2048 2>/dev/null
                openssl req -x509 -new -nodes -key "$SSL_KEY" -days 365 \
                    -out "$SSL_CERT" \
                    -subj "/CN=depthnet/O=DepthNet/C=US" \
                    -addext "subjectAltName=IP:127.0.0.1,DNS:localhost,DNS:depthnet" \
                    2>/dev/null
                echo "✓ SSL certificate generated (valid 1 year)"
            else
                echo "✓ SSL certificate OK"
            fi
            ln -sf /etc/nginx/sites-available/ssl /etc/nginx/sites-enabled/ssl
            ;;
        "custom")
            if [ -f "$SSL_CERT" ] && [ -f "$SSL_KEY" ]; then
                echo "✓ Custom SSL certificates found"
                ln -sf /etc/nginx/sites-available/ssl /etc/nginx/sites-enabled/ssl
            else
                echo "Warning: SSL_MODE=custom but no certificates found in $SSL_DIR, HTTPS disabled"
            fi
            ;;
        "off")
            echo "SSL disabled (SSL_MODE=off)"
            rm -f /etc/nginx/sites-enabled/ssl
            ;;
        *)
            echo "Warning: Unknown SSL_MODE=$SSL_MODE, skipping HTTPS"
            ;;
    esac
}

fix_permissions() {
    # Fix ALL permissions at once!
    echo "Fixing project permissions..."
    chown -R depthnet:depthnet $APP_DIR
    chmod -R 755 $APP_DIR
    # More strict for some
    chmod -R 775 $APP_DIR/storage $APP_DIR/bootstrap/cache
    [ -d "$APP_DIR/node_modules" ] && chmod -R 775 $APP_DIR/node_modules
    [ -d "$APP_DIR/vendor" ] && chmod -R 775 $APP_DIR/vendor
    echo "Permissions fixed!"
}

# Initialize application if needed (only on first run)
if [ ! -f storage/app/.docker_initialized ]; then
    echo "Initializing application..."

    # Install dependencies
    if is_production; then
        echo "Installing production PHP dependencies..."
        composer install --optimize-autoloader --no-dev
    else
        echo "Installing development PHP dependencies..."
        composer install --optimize-autoloader
    fi
    if [ $? -ne 0 ]; then
        echo "Failed to install composer dependencies"
        exit 1
    fi

    # App key generation
    if ! grep -q "APP_KEY=base64:" .env; then
        echo "Generating application key..."
        php artisan key:generate --force
    fi

    # Database setup
    echo "Waiting for database connection..."
    MYSQL_OPTS="-h$DB_HOST -u$DB_USERNAME -p$DB_PASSWORD"
    MYSQL_CMD="mysql $MYSQL_OPTS -D$DB_DATABASE"

    # Auto-detect SSL configuration
    if [ -n "$DB_SSL_CA" ] && [ -f "$DB_SSL_CA" ]; then
        echo "Using SSL connection with certificates"
        MYSQL_CMD="$MYSQL_CMD --ssl-ca=$DB_SSL_CA"
        [ -n "$DB_SSL_CERT" ] && MYSQL_CMD="$MYSQL_CMD --ssl-cert=$DB_SSL_CERT"
        [ -n "$DB_SSL_KEY" ] && MYSQL_CMD="$MYSQL_CMD --ssl-key=$DB_SSL_KEY"
        MYSQL_CMD="$MYSQL_CMD --ssl-mode=REQUIRED"
    elif [ "$DB_SSL_MODE" = "REQUIRED" ]; then
        echo "SSL required but no certificates provided"
        MYSQL_CMD="$MYSQL_CMD --ssl-mode=REQUIRED"
    elif [ "$DB_SSL_MODE" = "VERIFY_CA" ] || [ "$DB_SSL_MODE" = "VERIFY_IDENTITY" ]; then
        MYSQL_CMD="$MYSQL_CMD --ssl-mode=$DB_SSL_MODE"
    else
        echo "Using non-SSL connection"
        MYSQL_CMD="$MYSQL_CMD --skip-ssl"
    fi

    MAX_TRIES=30
    TRIES=0

    until $MYSQL_CMD -e 'SELECT 1' > /dev/null 2>&1; do
        TRIES=$((TRIES+1))
        if [ $TRIES -ge $MAX_TRIES ]; then
            echo "ERROR: Failed to connect to database after $MAX_TRIES attempts"
            echo "Connection details:"
            echo "  Host: $DB_HOST"
            echo "  User: $DB_USERNAME"
            echo "  Database: $DB_DATABASE"
            echo "  SSL Mode: ${DB_SSL_MODE:-DISABLED}"
            echo ""
            echo "Last error:"
            $MYSQL_CMD -e 'SELECT 1'
            exit 1
        fi
        echo "Waiting for database... (attempt $TRIES/$MAX_TRIES)"
        sleep 3
    done
    echo "✓ Database connection established"

    if ! php artisan migrate --force; then
        echo "Failed to run migrations"
        exit 1
    fi

    if ! php artisan db:seed --force; then
        echo "Failed to seed database"
        exit 1
    fi

    # Frontend setup
    setup_frontend

    touch storage/app/.docker_initialized
    echo "Application initialized successfully!"
else

    # For subsequent runs, just update dependencies
    if is_production; then
        composer install --optimize-autoloader --no-dev
    else
        composer install --optimize-autoloader
    fi

    # Check if we need to rebuild assets
    if is_production && [ ! -d "public/build" ]; then
        echo "Production mode but no built assets found - rebuilding..."
        setup_frontend
    fi

fi

fix_permissions
setup_ssl

if ! is_production; then
    php artisan optimize:clear 2>/dev/null || true
fi

# Start supervisor
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf &
SUPERVISOR_PID=$!

# Wait a bit for supervisor to initialize
sleep 3

# Check if supervisor process is still running
if ! kill -0 $SUPERVISOR_PID 2>/dev/null; then
    echo "Supervisor failed to start"
    exit 1
fi

echo "✓ Supervisor started successfully"

# Start Vite only in development mode and if enabled
if [ -f storage/app/.docker_initialized ] && ! is_production; then
    echo "Starting Vite development server..."
    supervisorctl start vite
else
    echo "Vite disabled - using built assets or production mode"
fi

# Wait for supervisor process
wait $SUPERVISOR_PID
