FROM ubuntu:22.04

ARG DOCKER_GID=999

# Turn off interactive requests
ENV DEBIAN_FRONTEND=noninteractive

# Base toolbox
RUN apt-get update && apt-get install -y \
    curl \
    wget \
    jq \
    ca-certificates \
    gnupg \
    lsb-release \
    && rm -rf /var/lib/apt/lists/*

# Install Docker CLI (Only client, not Docker Engine)
RUN curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update \
    && apt-get install -y docker-ce-cli \
    && rm -rf /var/lib/apt/lists/*

# Working directory
WORKDIR /sandbox-manager

COPY scripts/ ./scripts/
COPY templates/ ./templates/

RUN chmod +x ./scripts/*.sh

RUN groupadd -g ${DOCKER_GID} docker \
    && useradd -m -s /bin/bash sandbox-manager \
    && usermod -aG docker sandbox-manager

# THEN modify .bashrc with welcome and alias
RUN echo './scripts/welcome.sh' >> /home/sandbox-manager/.bashrc \
 && echo 'alias sm="./scripts/manager.sh"' >> /home/sandbox-manager/.bashrc \
 && echo 'alias manager="./scripts/manager.sh"' >> /home/sandbox-manager/.bashrc \
 && echo 'export PS1="\[\e[0;33m\]\u@\h \[\e[0;32m\]\w \[\e[0;36m\]\t\[\e[0m\] \$ "' >> /home/sandbox-manager/.bashrc

USER sandbox-manager

CMD ["tail", "-f", "/dev/null"]
