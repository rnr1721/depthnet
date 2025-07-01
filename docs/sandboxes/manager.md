# Sandbox Manager

A powerful Docker-in-Docker sandbox management tool for creating, managing, and interacting with isolated development environments.

## Overview

Sandbox Manager is a comprehensive Bash script that provides a complete lifecycle management solution for Docker containers in a sandboxed environment. It's designed to run inside a Docker container and manage other containers on the same Docker daemon, making it perfect for development workflows, testing environments, and educational purposes.

## Features

### Core Functionality
- **Create** sandboxes with custom configurations
- **Start/Stop** sandbox containers with proper lifecycle management
- **Execute** commands in running sandboxes
- **Interactive shell access** via `dive` command
- **Reset** sandboxes to clean state
- **Destroy** sandboxes completely

### Image Management
- **Template-based** image building from Dockerfiles
- **Rebuild** images with cache invalidation
- **Remove** unused images with safety checks
- **Template discovery** and listing

### Safety & Security
- **Self-protection** - cannot terminate the manager itself
- **Resource limits** - memory and CPU constraints
- **Security hardening** - dropped capabilities, restricted tmpfs
- **Permission management** - proper UID/GID handling

### Monitoring & Diagnostics
- **List** all sandboxes (running and stopped)
- **Shared directory** management and viewing
- **System diagnostics** with comprehensive health checks
- **Detailed logging** with multiple severity levels

## Quick Start

### Basic Usage

```bash
# Show available templates
./sandbox-manager.sh templates

# Create a new sandbox
./sandbox-manager.sh create ubuntu-full my-project

# Create with custom ports
./sandbox-manager.sh create ubuntu-full web-app 3000,8080

# Dive into interactive shell
./sandbox-manager.sh dive my-project

# Execute a command
./sandbox-manager.sh exec my-project "python3 -m http.server 8000"

# List all sandboxes
./sandbox-manager.sh list all

# Clean up when done
./sandbox-manager.sh destroy my-project
```

## Commands Reference

### Container Management

| Command | Syntax | Description |
|---------|--------|-------------|
| `create` | `create [type] [name] [ports]` | Create new sandbox |
| `start` | `start <name>` | Start stopped sandbox |
| `stop` | `stop <name> [timeout]` | Stop running sandbox |
| `destroy` | `destroy <name>` | Remove sandbox completely |
| `reset` | `reset <name> [type]` | Reset sandbox to clean state |
| `list` | `list [all]` | List sandboxes |

### Interaction

| Command | Syntax | Description |
|---------|--------|-------------|
| `exec` | `exec <name> <command> [user] [timeout]` | Execute command in sandbox |
| `dive` | `dive <name> [user] [shell]` | Interactive shell into sandbox |

### Image Management

| Command | Syntax | Description |
|---------|--------|-------------|
| `templates` | `templates` | Show available templates |
| `rebuild` | `rebuild [type] [force]` | Rebuild sandbox image |
| `rmi` | `rmi <type> [force]` | Remove sandbox image |
| `purge` | `purge <type>` | Completely purge image (force rebuild) |

### System Operations

| Command | Syntax | Description |
|---------|--------|-------------|
| `shared` | `shared` | Show shared directories |
| `cleanup` | `cleanup` | Remove all sandboxes |
| `current` | `current` | Show current container name |
| `diagnose` | `diagnose` | Run system diagnostics |
| `config` | `config` | Show current configuration |

## Examples

### Development Workflow

```bash
# 1. Check available templates
./sandbox-manager.sh templates

# 2. Create development environment
./sandbox-manager.sh create ubuntu-full dev-env 3000,5000

# 3. Start coding session
./sandbox-manager.sh dive dev-env

# Inside the sandbox:
# - Install dependencies
# - Run your development server
# - Access via http://localhost:3000

# 4. Execute commands from outside
./sandbox-manager.sh exec dev-env "npm install"
./sandbox-manager.sh exec dev-env "npm start"

# 5. Clean up when done
./sandbox-manager.sh destroy dev-env
```

### Testing Multiple Configurations

```bash
# Create multiple test environments
./sandbox-manager.sh create ubuntu-full test-node-16 3001
./sandbox-manager.sh create ubuntu-full test-node-18 3002
./sandbox-manager.sh create ubuntu-full test-python 3003

# Run tests in each
./sandbox-manager.sh exec test-node-16 "node --version && npm test"
./sandbox-manager.sh exec test-node-18 "node --version && npm test"
./sandbox-manager.sh exec test-python "python --version && pytest"

# Clean up all at once
./sandbox-manager.sh cleanup
```

### Educational Environment

```bash
# Create learning sandbox with common tools
./sandbox-manager.sh create ubuntu-full learning-env 8080,9000

# Students can dive in and experiment
./sandbox-manager.sh dive learning-env

# Reset to clean state between sessions
./sandbox-manager.sh reset learning-env
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `SANDBOX_PREFIX` | `depthnet-sandbox` | Prefix for container names |
| `SANDBOX_NETWORK` | `depthnet_depthnet` | Docker network for sandboxes |
| `HOST_UID` | `1000` | User ID for file permissions |
| `HOST_GID` | `1000` | Group ID for file permissions |
| `HOST_SHARED_PATH` | Required | Path to shared directory |
| `SANDBOX_MEMORY` | `512m` | Memory limit per sandbox |
| `SANDBOX_CPUS` | `1.0` | CPU limit per sandbox |
| `SANDBOX_TMPFS_SIZE` | `100m` | Temporary filesystem size |
| `SANDBOX_SECURITY_MODE` | `permissive` | Security mode (strict/permissive) |
| `SANDBOX_DROP_CAPS` | `false` | Drop all capabilities |
| `SANDBOX_DEFAULT_TIMEOUT` | `30` | Default command timeout (seconds) |
| `SANDBOX_DEFAULT_USER` | `sandbox-user` | Default user for exec/dive |
| `SANDBOX_DEFAULT_SHELL` | `bash` | Default shell for dive |

### Resource Limits

Each sandbox is created with configurable constraints (via environment variables):
- **Memory**: 512MB (default, configurable via `SANDBOX_MEMORY`)
- **CPU**: 1.0 core (default, configurable via `SANDBOX_CPUS`)
- **Temporary storage**: 100MB (default, configurable via `SANDBOX_TMPFS_SIZE`)

### Security Features

- Configurable security modes (strict/permissive via `SANDBOX_SECURITY_MODE`)
- Optional capability dropping (`SANDBOX_DROP_CAPS`)
- Non-executable temporary filesystem
- User namespace isolation
- Network isolation via custom Docker network

## Templates

Templates are Dockerfile-based configurations stored in the `templates/` directory. Each template defines a specific environment type.

### Template Structure

```dockerfile
# ubuntu-full.dockerfile
# Full Ubuntu development environment with common tools

FROM ubuntu:22.04

# Install development tools
RUN apt-get update && apt-get install -y \
    curl \
    git \
    vim \
    python3 \
    python3-pip \
    nodejs \
    npm

# Create sandbox user
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN groupadd -g $HOST_GID sandbox-user && \
    useradd -u $HOST_UID -g $HOST_GID -m sandbox-user

USER sandbox-user
WORKDIR /home/sandbox-user
```

### Adding New Templates

1. Create a new `.dockerfile` in the `templates/` directory
2. Follow the naming convention: `<type>.dockerfile`
3. Include build arguments for `HOST_UID` and `HOST_GID`
4. Create a `sandbox-user` with proper permissions

## Troubleshooting

### Common Issues

**Container fails to start:**
```bash
# Check logs
./sandbox-manager.sh list all
docker logs <container-name>

# Run diagnostics
./sandbox-manager.sh diagnose
```

**Permission issues:**
```bash
# Check current user mapping
./sandbox-manager.sh current

# Verify shared directory permissions
./sandbox-manager.sh shared
```

**Network connectivity problems:**
```bash
# Verify network exists
docker network ls | grep depthnet

# Check network configuration
./sandbox-manager.sh diagnose
```

**Port conflicts:**
```bash
# Check port usage before creating
netstat -tuln | grep :<port>

# Use different ports
./sandbox-manager.sh create ubuntu-full app 3001,8081
```

**Configuration issues:**
```bash
# Check current configuration
./sandbox-manager.sh config

# Verify environment variables are set correctly
docker exec sandbox-manager env | grep SANDBOX_
```

### Debugging Mode

Enable debug output by modifying the script:
```bash
# Add after set -e
set -x  # Enable debug mode
```

## Best Practices

### Performance
- Use `cleanup` regularly to remove unused containers
- Remove unused images with `rmi` command
- Monitor resource usage with `diagnose`

### Security
- Don't run as root unless necessary
- Use specific user accounts in `exec` and `dive` commands
- Regularly update base images with `rebuild`

### Organization
- Use meaningful sandbox names
- Group related sandboxes with consistent naming
- Document custom templates with comments

## Integration

### CI/CD Pipeline
```bash
# Test in clean environment
./sandbox-manager.sh create test-env test-$(date +%s)
./sandbox-manager.sh exec test-env "make test"
EXIT_CODE=$?
./sandbox-manager.sh destroy test-env
exit $EXIT_CODE
```

### Development Scripts
```bash
#!/bin/bash
# dev-setup.sh
./sandbox-manager.sh create ubuntu-full dev-${USER} 3000
./sandbox-manager.sh exec dev-${USER} "git clone https://github.com/user/repo.git"
./sandbox-manager.sh dive dev-${USER}
```

## Contributing

When extending the sandbox manager:

1. Follow the existing code style and patterns
2. Add comprehensive error handling
3. Include usage examples in help text
4. Test with various container states
5. Ensure backward compatibility

## License

This project is part of the DepthNet ecosystem. See the main project documentation for licensing information.

---

**Note**: This tool is designed to run within a Docker container environment and requires appropriate Docker daemon access and network configuration.
