FROM php:8.2-fpm

ARG DOCKER_UID=1000
ARG DOCKER_GID=1000
ARG DOCKER_SOCKET_GID=999

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash -

# System dependencies
RUN apt-get update && apt-get install -y \
    mc \
    git \
    curl \
    libicu-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nodejs \
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
    ca-certificates \
    gnupg \
    lsb-release \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl && rm -rf /var/lib/apt/lists/*

# Install Docker CLI (client only, not the daemon)
RUN curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/debian $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update \
    && apt-get install -y docker-ce-cli \
    && rm -rf /var/lib/apt/lists/*

# Latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create app user and group

RUN groupadd -g $DOCKER_GID depthnet && \
    useradd -u $DOCKER_UID -g $DOCKER_GID -m depthnet

# Add Docker group and add depthnet user to it
RUN groupadd -g $DOCKER_SOCKET_GID docker || groupmod -g $DOCKER_SOCKET_GID docker \
    && usermod -aG docker depthnet

COPY docker/welcome.sh /usr/local/bin/welcome
RUN chmod +x /usr/local/bin/welcome
RUN echo 'export PS1="\[\e[0;33m\]\u@\h \[\e[0;32m\]\w \[\e[0;36m\]\t\[\e[0m\] \$ "' >> /home/depthnet/.bashrc
RUN echo 'export PS1="\[\e[1;31m\]root@\h \[\e[0;32m\]\w \[\e[0;36m\]\t\[\e[0m\] # "' >> /root/.bashrc
RUN echo 'source /etc/bash_completion' >> /home/depthnet/.bashrc
RUN echo '/usr/local/bin/welcome' >> /home/depthnet/.bashrc
RUN echo '/usr/local/bin/welcome' >> /root/.bashrc

COPY docker/aliases.sh /etc/depthnet_aliases
RUN echo 'source /etc/depthnet_aliases' >> /home/depthnet/.bashrc && \
    echo 'source /etc/depthnet_aliases' >> /root/.bashrc

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
COPY --chown=depthnet:depthnet stubs/robots.stub /var/www/html/public/robots.txt
RUN chmod 775 /var/www/html/public/robots.txt

# Ensure correct permissions for entire application
RUN chown -R depthnet:depthnet /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
