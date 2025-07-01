# TEMPLATE DESCRIPTION: Ubuntu Full (Sudo, Python, Node.js, PHP, tools)
FROM ubuntu:22.04

ARG HOST_UID=1000
ARG HOST_GID=1000

# Turn off interactive requests
ENV DEBIAN_FRONTEND=noninteractive

# Main toolbox
RUN apt-get update && apt-get install -y \
    curl \
    wget \
    git \
    vim \
    nano \
    htop \
    tree \
    sshpass \
    jq \
    unzip \
    zip \
    sudo \
    build-essential \
    software-properties-common \
    ca-certificates \
    gnupg \
    lsb-release \
    && rm -rf /var/lib/apt/lists/*

# Python 3 + pip + some popular
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    python3-dev \
    build-essential \
    && ln -sf /usr/bin/python3 /usr/bin/python \
    && python3 -m pip install --upgrade pip \
    && python3 -m pip install --break-system-packages \
        requests \
        beautifulsoup4 \
        pandas \
        numpy \
        matplotlib \
        fastapi \
        sqlalchemy \
        psycopg2-binary \
        redis \
        aiohttp \
        click \
    && rm -rf /var/lib/apt/lists/*

# Node.js 20 + npm + some popular
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g \
        typescript \
        ts-node \
        nodemon \
        pm2 \
        express \
        axios \
        lodash \
        moment \
        chalk \
        commander \
        inquirer \
        @types/node \
        eslint \
        prettier \
    && rm -rf /var/lib/apt/lists/*

# PHP 8.2 + Composer
RUN add-apt-repository ppa:ondrej/php -y \
    && apt-get update \
    && apt-get install -y \
        php8.2-cli \
        php8.2-common \
        php8.2-curl \
        php8.2-mbstring \
        php8.2-xml \
        php8.2-zip \
        php8.2-mysql \
        php8.2-sqlite3 \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

# Go (Just to be)
RUN wget https://go.dev/dl/go1.21.5.linux-amd64.tar.gz \
    && tar -C /usr/local -xzf go1.21.5.linux-amd64.tar.gz \
    && rm go1.21.5.linux-amd64.tar.gz
ENV PATH="/usr/local/go/bin:${PATH}"

# Rust (Just to be)
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
ENV PATH="/root/.cargo/bin:${PATH}"

# Dev toolbox
RUN apt-get update && apt-get install -y \
    sqlite3 \
    redis-tools \
    postgresql-client \
    mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Create a group and user (rights will be overridden via --user)
RUN groupadd -g ${HOST_GID} sandbox-group 2>/dev/null || true
RUN useradd -u ${HOST_UID} -g ${HOST_GID} -m -s /bin/bash sandbox-user \
 && echo "sandbox-user:sandbox123" | chpasswd \
 && usermod -aG sudo sandbox-user \
 && echo "sandbox-user ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers \
 && echo "Defaults !requiretty" >> /etc/sudoers

# User workspace
USER sandbox-user
WORKDIR /home/sandbox-user

# Create .bashrc with correct permissions
RUN echo 'export PATH="/usr/local/go/bin:/root/.cargo/bin:$PATH"' >> ~/.bashrc \
    && echo 'alias ll="ls -la"' >> ~/.bashrc \
    && echo 'alias la="ls -A"' >> ~/.bashrc \
    && echo 'alias l="ls -CF"' >> ~/.bashrc \
    && echo 'alias ..="cd .."' >> ~/.bashrc \
    && echo 'alias ...="cd ../.."' >> ~/.bashrc \
    && echo 'alias tree="tree -C"' >> ~/.bashrc

# Welcome message
RUN echo 'echo "Welcome to AI Sandbox!"' >> ~/.bashrc \
 && echo 'echo "Available: Python 3.11, Node.js 20, PHP 8.2, Go 1.21, Rust"' >> ~/.bashrc \
 && echo 'echo "Current user: $(whoami) (UID: $(id -u), GID: $(id -g))"' >> ~/.bashrc \
 && echo 'echo "Home permissions: $(ls -ld ~ | cut -d\" \" -f1,3,4)"' >> ~/.bashrc \
 && echo 'echo "Try: python --version, node --version, php --version"' >> ~/.bashrc \
 && echo 'cd /home/sandbox-user 2>/dev/null || true' >> ~/.bashrc

USER sandbox-user

# Force home directory
ENV HOME=/home/sandbox-user
WORKDIR /home/sandbox-user

CMD ["bash"]
