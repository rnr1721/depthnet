#!/bin/bash
set -e

# Get UID/GID from environment or use defaults
DOCKER_UID=${DOCKER_UID:-1000}
DOCKER_GID=${DOCKER_GID:-1000}

# Only set core.fileMode if not already disabled
cd /var/www/html
if ! git config --get core.fileMode | grep -q false; then
    git config core.fileMode false
fi

# Initialize application if needed (only on first run)
if [ ! -f /tmp/.app_initialized ]; then
    echo "Initializing application..."
    
    # Create .env if it doesn't exist
    if [ ! -f /var/www/html/.env ]; then
        cp /var/www/html/.env.example.docker /var/www/html/.env
        php artisan key:generate
    fi
    
    # Wait for database
    until mysql -h mysql -u depthnet -psecret -D depthnet -e 'SELECT 1' > /dev/null 2>&1; do
        echo 'Waiting for database...'
        sleep 3
    done
    
    # Install dependencies
    composer install --optimize-autoloader
    npm install
    npm run build
    
    # Run migrations and seeds
    php artisan migrate --force
    php artisan db:seed --force

    # Generate ziggy
    php artisan ziggy:generate
    
    # Cache config
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    touch /tmp/.app_initialized
    echo "Application initialized successfully!"
fi

# Start supervisor
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
