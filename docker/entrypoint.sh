#!/bin/bash
set -e

# Get UID/GID from environment or use defaults
DOCKER_UID=${DOCKER_UID:-1000}
DOCKER_GID=${DOCKER_GID:-1000}
APP_DIR="${APP_DIR:-/var/www/html}"

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

# Only set core.fileMode if not already disabled
if ! git config --get core.fileMode | grep -q false; then
    git config core.fileMode false
fi

# Create .env if it doesn't exist
if [ ! -f .env ]; then
    cp .env.example.docker .env
fi

# Initialize application if needed (only on first run)
if [ ! -f storage/app/.docker_initialized ]; then
    echo "Initializing application..."

    # Install dependencies
    composer install --optimize-autoloader
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
    until mysql -h mysql -u depthnet -psecret -D depthnet -e 'SELECT 1' > /dev/null 2>&1; do
        echo 'Waiting for database...'
        sleep 3
    done

    if ! php artisan migrate --force; then
        echo "Failed to run migrations"
        exit 1
    fi

    if ! php artisan db:seed --force; then
        echo "Failed to seed database"
        exit 1
    fi

    # Frontend
    echo "Installing Node.js dependencies..."
    if ! npm install; then
        echo "Failed to install npm dependencies"
        exit 1
    fi

    # Run custom setup script
    composer setup

    touch storage/app/.docker_initialized
    echo "Application initialized successfully!"
else
    # For subsequent runs, just update dependencies
    composer install --optimize-autoloader
fi

# Fix ALL fuckin permissions at once!
echo "Fixing project permissions..."
chown -R depthnet:depthnet $APP_DIR
chmod -R 755 $APP_DIR
# More strict for some
chmod -R 775 $APP_DIR/storage $APP_DIR/bootstrap/cache
[ -d "$APP_DIR/node_modules" ] && chmod -R 775 $APP_DIR/node_modules
[ -d "$APP_DIR/vendor" ] && chmod -R 775 $APP_DIR/vendor
echo "Permissions fixed!"

# Start supervisor
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf &
SUPERVISOR_PID=$!

sleep 3

if [ -f storage/app/.docker_initialized ] && [ "$APP_ENV" = "local" ]; then
    echo "Starting Vite development server..."
    supervisorctl start vite
elif [ "$APP_ENV" != "local" ]; then
    echo "Production mode - Vite disabled"
fi

# Wait for supervisor process
wait $SUPERVISOR_PID
