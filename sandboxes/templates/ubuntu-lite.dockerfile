# TEMPLATE DESCRIPTION: Ubuntu Light (Base tools, no programming languages)
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
    build-essential \
    software-properties-common \
    ca-certificates \
    gnupg \
    lsb-release \
    && rm -rf /var/lib/apt/lists/*

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
 && echo "sandbox-user:sandbox123" | chpasswd

# User workspace
USER sandbox-user
WORKDIR /home/sandbox-user

# Create .bashrc with correct permissions
RUN echo 'export PATH="$PATH"' >> ~/.bashrc \
    && echo 'alias ll="ls -la"' >> ~/.bashrc \
    && echo 'alias la="ls -A"' >> ~/.bashrc \
    && echo 'alias l="ls -CF"' >> ~/.bashrc \
    && echo 'alias ..="cd .."' >> ~/.bashrc \
    && echo 'alias ...="cd ../.."' >> ~/.bashrc \
    && echo 'alias tree="tree -C"' >> ~/.bashrc

# Welcome message
RUN echo 'echo "Welcome to AI Sandbox!"' >> ~/.bashrc \
 && echo 'echo "Current user: $(whoami) (UID: $(id -u), GID: $(id -g))"' >> ~/.bashrc \
 && echo 'echo "Home permissions: $(ls -ld ~ | cut -d\" \" -f1,3,4)"' >> ~/.bashrc \
 && echo 'echo "Try: git --version, curl --version, sqlite3 --version"' >> ~/.bashrc \
 && echo 'cd /home/sandbox-user 2>/dev/null || true' >> ~/.bashrc

USER sandbox-user

# Force home directory
ENV HOME=/home/sandbox-user
WORKDIR /home/sandbox-user

CMD ["bash"]
