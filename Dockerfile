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
    vim \
    bash-completion \
    htop \
    lsof \
    tzdata \
    tree \
    net-tools \
    iputils-ping \
    dnsutils \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create app user and group
RUN groupadd -g $DOCKER_GID depthnet && \
    useradd -u $DOCKER_UID -g $DOCKER_GID -m depthnet

RUN echo 'export PS1="\[\e[0;33m\]\u@\h \[\e[0;32m\]\w \[\e[0;36m\]\t\[\e[0m\] \$ "' >> /home/depthnet/.bashrc
RUN echo 'export PS1="\[\e[1;31m\]root@\h \[\e[0;32m\]\w \[\e[0;36m\]\t\[\e[0m\] # "' >> /root/.bashrc
RUN echo 'source /etc/bash_completion' >> /home/depthnet/.bashrc

# Working directory
WORKDIR /var/www/html

# Configure git
RUN git config --global --add safe.directory /var/www/html

# Configure PHP-FPM
RUN sed -i 's/^user = .*/user = depthnet/' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's/^group = .*/group = depthnet/' /usr/local/etc/php-fpm.d/www.conf

COPY --chown=depthnet:depthnet . .

COPY docker/php-custom.ini /usr/local/etc/php/conf.d/99-custom.ini

# Nginx config
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Nginx to run as depthnet
RUN mkdir -p /var/log/nginx /var/run && \
    chown -R depthnet:depthnet /var/log/nginx /var/run
RUN sed -i "s/user www-data;/user depthnet depthnet;/" /etc/nginx/nginx.conf

# Supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# robots.txt
RUN echo "User-agent: *\nDisallow: /" > /var/www/html/public/robots.txt

# Ensure correct permissions for entire application
RUN chown -R depthnet:depthnet /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
