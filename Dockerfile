FROM php:8.2-fpm

ARG DOCKER_UID=1000
ARG DOCKER_GID=1000

# System dependencies
RUN apt-get update && apt-get install -y \
    mc \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nodejs \
    npm \
    supervisor \
    nginx \
    default-mysql-client \
    netcat-traditional \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create app user and group
RUN groupadd -g $DOCKER_GID appgroup && \
    useradd -u $DOCKER_UID -g $DOCKER_GID -m appuser

# Working directory
WORKDIR /var/www/html

COPY . .

# Configure git
RUN git config --global --add safe.directory /var/www/html

# Configure PHP-FPM
RUN echo "[www]" > /usr/local/etc/php-fpm.d/custom.conf && \
    echo "user = appuser" >> /usr/local/etc/php-fpm.d/custom.conf && \
    echo "group = appgroup" >> /usr/local/etc/php-fpm.d/custom.conf

# Nginx config
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Nginx to run as appuser
RUN mkdir -p /var/log/nginx /var/run && \
    chown -R appuser:appgroup /var/log/nginx /var/run
RUN sed -i "s/user www-data;/user appuser;/" /etc/nginx/nginx.conf

# Supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# robots.txt
RUN echo "User-agent: *\nDisallow: /" > /var/www/html/public/robots.txt

EXPOSE 80

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
