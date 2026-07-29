# How to deploy with Docker (Quick start)

## Prerequisites
- Docker & Docker Compose
- Git
- Make (optional, can use docker/manager.sh directly)

## Installation

```bash
# Clone repository
git clone git@github.com:rnr1721/depthnet.git
# or
git clone https://github.com/rnr1721/depthnet.git
cd depthnet
```

## Quick Start

### Quickest Start (Interactive Setup)

```bash
chmod +x ./docker/manager.sh
make setup        # Interactive setup for development
make start
```

The interactive setup will ask you for:
- `APP_URL` — your IP/domain and port
- Timezone
- Database password (optional)

Everything else is configured automatically.

### For Production Environment

**Full mode (with sandbox for AI features):**
```bash
# Setup production environment with sandbox
chmod +x ./docker/manager.sh
make setup-prod-full
# or: ./docker/manager.sh setup-prod-full

# Edit .env and start
vim .env
make start
```

**Lightweight mode (recommended for most production deployments):**
```bash
# Setup production environment (no sandbox)
chmod +x ./docker/manager.sh
make setup-prod
# or: ./docker/manager.sh setup-prod

# IMPORTANT: Edit .env file and configure:
# - APP_URL (your domain)
# - DB_PASSWORD (strong password)
# - DB_ROOT_PASSWORD (strong root password)
vim .env

# Start application
make start
# or: ./docker/manager.sh start
```

### For Development Environment

**Lightweight mode (faster startup, no sandbox):**
```bash
# Setup development environment (no sandbox)
chmod +x ./docker/manager.sh
make setup-dev
# or: ./docker/manager.sh setup-dev

# Optional: Edit .env file if you need custom settings
# vim .env

# Start application
make start
# or: ./docker/manager.sh start
```

**Full mode (with sandbox support):**
```bash
# Setup development environment with sandbox
chmod +x ./docker/manager.sh
make setup-dev-full
# or: ./docker/manager.sh setup-dev-full

# Start application
make start
```

## Sandbox Management

The system supports **optional sandbox containers** for code execution and AI features.

### Toggle Sandbox Support

```bash
# Enable sandbox support
make sandbox-enable
# or: ./docker/manager.sh sandbox-toggle enable

# Disable sandbox support (saves resources)
make sandbox-disable
# or: ./docker/manager.sh sandbox-toggle disable

# After toggling, restart containers
make restart
```

### Sandbox Usage

```bash
# Check if sandbox is enabled
make urls  # Shows sandbox status

# List all sandbox containers
make sandbox-list

# Access sandbox manager
make sandbox

# Access specific sandbox
make sandbox name="my-test"

# Control sandbox containers
make sandbox-control action="list"
make sandbox-control action="start" name="test"
make sandbox-control action="stop" name="test"
make sandbox-control action="destroy" name="test"

# Clean up all sandboxes
make sandbox-cleanup
```

## Browser Service

The browser service gives agents a persistent Playwright-powered browser. Sessions are scoped per preset and survive across thinking cycles — an agent can open a page, reason about it for several cycles, and return without losing context.

### Enable / Disable

```bash
# Enable browser service
make browser-enable
# or: ./docker/manager.sh browser-toggle enable

# Disable browser service
make browser-disable
# or: ./docker/manager.sh browser-toggle disable

# After toggling, restart containers
make restart
```

### Check Browser Status

```bash
make status  # browser-service will appear in the list if enabled

# View browser service logs
make logs-service service="browser-service"
```

### Browser vs Crawler

| | Browser Plugin | Crawler Plugin |
|---|---|---|
| Engine | Playwright (Chromium) | HTTP fetch |
| JavaScript | ✅ Full support | ❌ No |
| Session / cookies | ✅ Persistent per preset | ❌ Stateless |
| Click / type / scroll | ✅ | ❌ |
| Docker required | ✅ (profile: browser) | ❌ |
| Best for | Interactive sites, SPAs | Simple scraping |

### Resource Requirements

The browser service runs a full Chromium instance per active session. Each session uses approximately 150–300 MB RAM. Keep this in mind when setting `BROWSER_MAX_SESSIONS` (default: 10).

### Configuration (`.env`)

```bash
BROWSER_SERVICE_URL=http://browser-service:3001  # internal URL (do not change for Docker)
BROWSER_SESSION_TTL=3600       # session lifetime in seconds (default: 1 hour)
BROWSER_MAX_SESSIONS=10        # max concurrent browser sessions
BROWSER_SNAPSHOT_TEXT_LIMIT=3000  # max characters of page text returned to agent
BROWSER_SNAPSHOT_LINKS_LIMIT=30   # max links returned in snapshot
```

## Voice Service

The voice service gives agents local speech recognition and synthesis —
[faster-whisper](https://github.com/SYSTRAN/faster-whisper) for transcription and
[Piper](https://github.com/rhasspy/piper) for speech, behind an OpenAI-compatible
HTTP API. Nothing spoken to or by an agent leaves the host.

Voice capabilities are configured per preset in **Admin → Capabilities**. This
container is one option; the browser's built-in Web Speech API needs no container
at all, and any OpenAI-compatible endpoint works too. See
[the voice interface guide](../ui/text-to-speech.md).

### Enable / Disable

```bash
# Enable voice service
make voice-on
# or: ./docker/manager.sh voice-toggle enable

# Disable voice service
make voice-off
# or: ./docker/manager.sh voice-toggle disable

# After toggling, restart containers
make restart
```

> **First start downloads models.** Whisper `small` is ~460 MB and each Piper
> voice ~60 MB. On a slow connection this takes several minutes, during which the
> container reports `health: starting` — that is expected, not a failure. Watch
> progress with `make logs-service service="voice-service"`.

Models live in a Docker volume, not in the image, so they survive rebuilds and
adding a voice downloads only that voice.

### Check Voice Status

```bash
make status  # voice-service appears in the list if enabled

# View voice service logs
make logs-service service="voice-service"
```

### Configuration (`.env`)

```bash
VOICE_SERVICE_URL=http://voice-service:3002  # internal URL (do not change for Docker)

# STT model size — tiny | base | small | medium | large-v3
#   tiny   ~75MB   fast, weak on Russian
#   base   ~140MB  usable for English
#   small  ~460MB  recommended default, decent multilingual
#   medium ~1.5GB  noticeably better, needs ~2G RAM and a fast CPU
VOICE_STT_MODEL=small

# Quantization — int8 (CPU, default) | int8_float16 | float16 (GPU)
VOICE_STT_COMPUTE=int8

# TTS voices to install, comma-separated. First one is the default.
# Full catalog: voice-service/voices.py
VOICE_TTS_VOICES=en_US-lessac-medium

# Request guards
VOICE_MAX_AUDIO_MB=25
VOICE_MAX_TEXT_CHARS=5000
```

Changing model or voices needs only `make restart` — new models download on the
next start, existing ones are kept.

### Resource Requirements

Whisper `small` with `int8` quantization uses roughly 500 MB RAM; `medium` needs
about 1.5 GB. The container has a 2 GB limit by default — raise it in the compose
file if you switch to a larger model.

On a mid-range CPU, transcription runs about **twice as fast as realtime** — an
8-second phrase takes around 4 seconds. Synthesis is much faster and effectively
instant for chat-length replies. A two-core VPS may drop to around realtime,
which is noticeable for dictation; `VOICE_STT_MODEL=base` is the fix.

### GPU (optional)

For CUDA, add a `docker-compose.override.yml` reserving the device and set
`VOICE_STT_COMPUTE=float16`. No code changes are involved — the container picks
the compute type up from the environment.

### Local vs Cloud

The container speaks the OpenAI audio API, so the same provider configuration
points equally at it, at `api.openai.com`, or at any other compatible server —
only `base_url` differs. Running locally is a configuration choice, not a
separate code path.

## Two Ways to Use

### Option 1: Make Commands (Recommended)
```bash
make help           # Show all available commands
make start          # Start containers
make urls           # Show application URLs and sandbox status
```

### Option 2: Direct Script Usage
```bash
./docker/manager.sh help        # Show help
./docker/manager.sh start       # Start containers
./docker/manager.sh urls        # Show URLs with port info
```

## Flexible URL Configuration

The system automatically resolves ports from your APP_URL configuration:

```bash
# Example 1: Custom port
APP_URL=http://192.168.1.5:3000

# Example 2: HTTPS (port 443 auto-detected)
APP_URL=https://mydev.local

# Example 3: HTTP without port (port 80 auto-detected)
APP_URL=http://192.168.1.5

# Example 4: Localhost with custom port
APP_URL=http://localhost:9000

# Example 5: HTTPS with self-signed certificate
APP_URL=https://192.168.1.5:8443
# APP_PORT and session settings are configured automatically
```

No need for manual port exports! Just edit your `.env` file and run `make start`.

### Check Configuration
```bash
# See how your URL/port is resolved
make ports
# or: ./docker/manager.sh ports

# View resolved URLs (with port info and sandbox status)
make urls
# or: ./docker/manager.sh urls
```

## Alternative Setup (Pure Docker)

If Make and bash scripts are not available:

```bash
# Development (no sandbox)
cp .env.example.docker .env
# Edit .env file as needed
docker compose up -d --build

# Development (with sandbox)
cp .env.example.docker .env
echo "COMPOSE_PROFILES=sandbox" >> .env
docker compose --profile sandbox up -d --build

# Development (with browser service)
cp .env.example.docker .env
echo "COMPOSE_PROFILES=browser" >> .env
docker compose --profile browser up -d --build

# Development (sandbox + browser)
cp .env.example.docker .env
echo "COMPOSE_PROFILES=sandbox,browser" >> .env
docker compose --profile sandbox --profile browser up -d --build

# Development (with voice service)
cp .env.example.docker .env
echo "COMPOSE_PROFILES=voice" >> .env
docker compose --profile voice up -d --build

# Development (everything)
cp .env.example.docker .env
echo "COMPOSE_PROFILES=sandbox,browser,voice" >> .env
docker compose --profile sandbox --profile browser --profile voice up -d --build

# Production (no sandbox)
cp .env.example.docker.prod .env
# Edit .env file (set APP_URL, passwords, etc.)
docker compose -f docker-compose.prod.yml up -d --build

# Production (with sandbox)
cp .env.example.docker.prod .env
echo "COMPOSE_PROFILES=sandbox" >> .env
docker compose -f docker-compose.prod.yml --profile sandbox up -d --build
```

## Access Points

After starting, check URLs with:
```bash
make urls
```

Output example:
```
 Application URLs:
    App: http://localhost:5000
    Port: 5000
    phpMyAdmin: http://localhost:8001
 Sandbox: Enabled
```

**Default Access:**
- **Application**: Automatically detected from your APP_URL
- **phpMyAdmin**: http://localhost:8001 (development only)
  - User: depthnet
  - Password: secret (or your configured DB_PASSWORD)
- **Sandbox Manager**: Interactive container management for code execution environments (if enabled)

## Services

### Core Services (Always Active)
- **app** - Laravel application (PHP 8.2-FPM + Nginx + Supervisor)
- **mysql** - MySQL 8.0 database
- **phpmyadmin** - Database administration interface (development only)

### Optional Services (Sandbox Profile)
- **sandbox-manager** - Sandbox container management service

The sandbox manager is only started when `COMPOSE_PROFILES=sandbox` is set in your `.env` file.

### Optional Services (Browser Profile)
- **browser-service** - Playwright browser service for agents

The browser service gives agents a persistent, stateful browser with session memory that survives across thinking cycles. It is only started when `COMPOSE_PROFILES=browser` (or `full`) is set in your `.env` file.

### Optional Services (Voice Profile)
- **voice-service** - Local speech recognition (faster-whisper) and synthesis (Piper)

The voice service lets agents hear and speak without sending audio to an external
provider. It is only started when `COMPOSE_PROFILES=voice` (or `full`) is set in
your `.env` file. Speech capabilities are configured per preset — this container
is one provider option among several.

## User Management & Security

The application automatically detects your host UID/GID and creates matching user inside container to prevent permission issues:
- Container user: depthnet:depthnet
- Mapped to your host UID/GID
- All services (nginx, php-fpm) run under this user
- **Sudo protection**: Script warns if running with sudo and uses original user's permissions

## Available Commands

### Environment Setup
```bash

make setup        # Interactive setup

make setup-dev       # Setup development environment (lightweight)
make setup-dev-full  # Setup development environment (with sandbox)
make setup-prod      # Setup production environment (lightweight)
make setup-prod-full # Setup production environment (with sandbox)

# Quick aliases
make dev            # = setup-dev
make full           # = setup-dev-full
make prod           # = setup-prod

make ports          # Show port resolution info
make urls           # Show application URLs with sandbox status
```

### Sandbox Management
```bash
make sandbox-enable     # Enable sandbox support
make sandbox-disable    # Disable sandbox support
make sandbox           # Open shell in sandbox manager
make sandbox name="test" # Open shell in specific sandbox

make sandbox-list      # List all sandbox containers
make sandbox-cleanup   # Destroy all sandbox containers
make sandbox-control action="list"                    # List sandboxes
make sandbox-control action="start" name="test"       # Start sandbox
make sandbox-control action="stop" name="test"        # Stop sandbox
make sandbox-control action="restart" name="test"     # Restart sandbox
make sandbox-control action="destroy" name="test"     # Destroy sandbox
```

### Browser Service Management
```bash
make browser-enable     # Enable Playwright browser service
make browser-disable    # Disable browser service
```

### Voice Service Management
```bash
make voice-on      # Enable local STT/TTS service (faster-whisper + Piper)
make voice-off     # Disable voice service
```

### Container Management
```bash
make start          # Build and start all services
make stop           # Stop all services
make restart        # Restart all services
make up             # Start services in foreground
make status         # Check status of services
```

### Development Tools
```bash
make shell          # Access container as depthnet user
make rootshell      # Access container as root
make logs           # View application logs
make logs-all       # View all container logs
make logs-service service="mysql"  # View specific service logs
```

### Laravel Commands
```bash
make artisan cmd="migrate"              # Run artisan commands
make composer cmd="install"             # Run composer commands

# Database shortcuts
make migrate                            # Run migrations
make migrate-fresh                      # Fresh migration with seeding
make migrate-rollback                   # Rollback last migration
make seed                              # Run database seeders

# Development shortcuts
make install                           # composer install
make update                           # composer update
make dump-autoload                    # composer dump-autoload
make clear-cache                      # Clear all Laravel caches
make optimize                         # Optimize for production
```

### Maintenance
```bash
make clean              # Clean up containers and reset initialization
make prune        # Remove containers and images, keep volumes (database data)
make reset        # Complete reset including volumes (WARNING: deletes all data)
make fix-permissions    # Fix file permissions after sudo usage
```

---

## Database Backup & Restore

```bash
# Create backup (saved to backups/ directory)
make backup
# or:
./docker/manager.sh backup
```

```bash
# Restore from backup
make restore file=backups/filename.sql
# or:
./docker/manager.sh restore backups/filename.sql
```

> ⚠️ **Note:** When using `make restore`, use the `file=` variable syntax, not a positional argument. `make restore backups/filename.sql` will **not** work.

Backups are stored in the `backups/` directory inside the project folder. It's recommended to copy them to an external location for safekeeping.

---

## Architecture

The Docker setup uses a **bash manager script** (`docker/manager.sh`) that handles all Docker operations with proper error handling, colored output, and automatic port resolution. The system supports **optional sandbox containers** using Docker Compose profiles.

**Key Scripts:**
- `docker/manager.sh` - Main Docker management utility
- `docker/port-resolver.sh` - Automatic port resolution from APP_URL
- `Makefile` - Convenient shortcuts that call manager.sh

**Profile Architecture:**
- **Lightweight mode**: Only core services (app, mysql, redis, phpmyadmin)
- **Optional services**: enabled per profile — `sandbox` (code execution),
  `browser` (Playwright), `voice` (local STT/TTS)
- **Profile-based**: `COMPOSE_PROFILES=sandbox,browser,voice` in `.env`, or
  `full` to enable all of them

## Deployment Modes

### Lightweight Mode (Recommended for most cases)
- **Faster startup**: ~30 seconds vs 2+ minutes
- **Lower resource usage**: ~500MB RAM vs 1.5GB+
- **Simpler architecture**: Only essential services
- **Perfect for**: Traditional web applications, APIs, small projects

### Full Mode (For AI/ML features)
- **Complete feature set**: All sandbox templates available
- **AI code execution**: Python, Node.js, PHP, Kali Linux environments
- **Dynamic containers**: On-demand creation and cleanup
- **Perfect for**: AI applications, code playgrounds, educational platforms

## Log Files

### File Locations
- **Nginx**: ./docker/logs/nginx/
- **Supervisor**: ./docker/logs/supervisor/
- **Laravel**: ./storage/logs/

### View Logs in Real-time
```bash
# Application logs (recommended)
make logs

# All container logs
make logs-all

# Specific service logs
make logs-service service="mysql"

# Specific Laravel logs
make shell
tail -f storage/logs/laravel.log

# Nginx logs
make shell
tail -f /var/log/nginx/access.log /var/log/nginx/error.log

# Sandbox manager logs (if enabled)
make logs-service service="sandbox-manager"
```

## Configuration Tips

### Development
- Use `APP_URL=https://192.168.1.5:8443` for network access with HTTPS
- SSL certificate is generated automatically (self-signed by default)
- IMPORTANT: When using HTTPS, open `https://your-ip:5173` in browser once and accept the certificate warning for Vite HMR to work
- `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS` and `VITE_HMR_HOST` are configured automatically from `APP_URL`
- Vite HMR automatically configured for Docker
- Start with lightweight mode, enable sandbox only if needed

### Production
- Always use HTTPS in production: `APP_URL=https://yourdomain.com`
- Set strong database passwords
- Configure proper CORS origins
- Use lightweight mode unless AI features are required
- Monitor resource usage if using sandbox or voice profiles
- Voice models are downloaded on first start — allow time and disk before the
  first deployment, or pre-warm the volume

## 🔐 SSL / HTTPS Configuration

HTTPS is enabled by default using a self-signed certificate. The certificate is generated automatically on first start.

### SSL Modes

Set `SSL_MODE` in your `.env`:

```bash
# Auto-generate self-signed certificate (default)
SSL_MODE=self-signed

# Use your own certificates (place them in docker/ssl/)
SSL_MODE=custom

# Disable HTTPS (use when behind external reverse proxy)
SSL_MODE=off
```

### Custom certificates (SSL_MODE=custom)

1. Copy the example override file:
```bash
cp docker-compose.override.example.yml docker-compose.override.yml
```

2. Uncomment the `OPTION 3: Custom SSL certificates` section in `docker-compose.override.yml`

3. Place your certificates into:
```
docker/ssl/
  ├── cert.pem
  └── key.pem
```

4. Set in `.env`:
```bash
SSL_MODE=custom
```

### HTTPS port

Default HTTPS port is `8443`. To change it:
```bash
HTTPS_PORT=443
APP_URL=https://yourdomain.com:443
```

### Vite HMR with HTTPS

When using HTTPS, Vite development server runs on a separate port (`5173`) with its own self-signed certificate. On first use you need to accept it in your browser:

1. Open `https://your-ip:5173` in browser
2. Accept the certificate warning
3. Return to your app — HMR will work automatically

## Default Admin Account

After installation, you can login with:

**Admin:**
- **Login:** admin@example.com
- **Password:** admin123

**Test User:**
- **Login:** test@example.com
- **Password:** password

## Windows Support

**Recommended approach for Windows users:**
- Use WSL2 (Windows Subsystem for Linux)
- Install Docker Desktop with WSL2 backend
- Clone and run the project inside WSL2

The bash scripts are designed for Unix-like systems and work best in WSL2 environment.

## Important Notes

- Configure plugins in the admin panel after first login
- Each plugin has individual settings
- For production, review all security settings in `.env`
- The port resolver automatically handles URL/port conflicts
- Script permissions are automatically fixed if needed
- Sandbox mode increases resource requirements significantly

## Troubleshooting

```bash
# Check project health
make ports

# View container status
make status

# Check detailed help
make help
# or: ./docker/manager.sh help

# Reset everything if issues occur
make reset
make setup-dev  # or setup-prod
make start

# Fix permissions after sudo usage
make fix-permissions
```

**Sandbox issues:**
```bash
# Check if sandbox is enabled
make urls

# Enable sandbox if needed
make sandbox-enable && make restart

# Check sandbox containers
make sandbox-control action="list"

# Access sandbox manager for debugging
make sandbox

# Clean up problematic sandboxes
make sandbox-control action="cleanup"
```

### Common Issues

**Permission errors:**
```bash
make fix-permissions
```

**Port conflicts:**
```bash
make ports  # Check current port resolution
# Edit APP_URL in .env to use different port
make restart
```

**Script not executable:**
```bash
chmod +x docker/manager.sh
# or just run make start (auto-fixes permissions)
```

**Sandbox not working:**
```bash
# Check if sandbox is enabled
make urls

# Enable if needed
make sandbox-enable
make restart

# Check sandbox manager logs
make logs-service service="sandbox-manager"
```

**Browser service not working:**
```bash
# Check if browser service is enabled
make status  # look for browser-service in the list

# Enable if needed
make browser-enable
make restart

# Check browser service logs
make logs-service service="browser-service"

# Test browser service directly
docker exec depthnet-browser-service-1 wget -qO- http://localhost:3001/health
# Expected: {"ok":true,"sessions":0}
```

**Voice service not working:**
```bash
# Check if voice service is enabled
make status  # look for voice-service in the list

# Enable if needed
make voice-on
make restart

# Watch startup — first run downloads models and takes minutes
make logs-service service="voice-service"

# Test directly (the service is not published to the host)
docker compose exec voice-service curl -s http://localhost:3002/health
# Expected: {"status":"ok","stt":{"ready":true,...},"tts":{...}}

# See which voices are actually installed
docker compose exec voice-service curl -s http://localhost:3002/v1/voices
```

**Voice service stuck at `health: starting`** — models are still downloading.
Normal on first start; the healthcheck allows 10 minutes for it.

**Voice service restarting repeatedly** — read the traceback in the logs. The
entrypoint waits 60 seconds before exiting, so the error stays readable instead
of scrolling past in a restart loop.

**`Unknown TTS voice`** — the name in `VOICE_TTS_VOICES` is not in the catalog.
Valid names are listed in `voice-service/voices.py`; the container refuses to
start rather than failing later on the first synthesis request.

**Microphone button missing in chat** — voice capabilities are configured per
preset in **Admin → Capabilities**, not by the container alone. Also note that
browsers require a secure context (HTTPS or localhost) for microphone access; see
[the voice interface guide](../capabilities/text-to-speech.md).

**HTTPS / Vite HMR not connecting:**
```bash
# Accept Vite certificate in browser
# Open https://your-ip:5173 and click "Accept risk"
# Then reload your app
```

**Login redirects back to login page:**
```bash
# Make sure APP_URL matches the address you use in browser
# SESSION_DOMAIN and SANCTUM_STATEFUL_DOMAINS are set automatically
# If issues persist, clear Laravel cache:
make artisan cmd="config:clear"
make artisan cmd="cache:clear"
```

**Resource issues:**
```bash
# Switch to lightweight mode
make sandbox-disable
make restart

# Or allocate more resources to Docker
# Docker Desktop: Settings → Resources → Advanced
```

**Need to rebuild after code changes:**
```bash
make prune   # removes images but keeps database
make start   # rebuilds from scratch
```

## Advanced Usage

### Running manager.sh directly
```bash
# All manager.sh commands
./docker/manager.sh help

# Examples
./docker/manager.sh setup-dev
./docker/manager.sh setup-dev-full
./docker/manager.sh sandbox-toggle enable
./docker/manager.sh browser-toggle enable
./docker/manager.sh voice-toggle enable
./docker/manager.sh start
./docker/manager.sh shell
./docker/manager.sh artisan "migrate"
./docker/manager.sh composer "install"
```

### Sandbox management via script
```bash
# Direct sandbox commands
./docker/manager.sh sandbox                    # Access sandbox manager
./docker/manager.sh sandbox test2              # Access specific sandbox
./docker/manager.sh sandbox-control list       # List sandboxes
./docker/manager.sh sandbox-control destroy test2  # Destroy sandbox
```

### Environment variables
```bash
# Check how port is resolved
./docker/port-resolver.sh .env info

# Export resolved values
eval $(./docker/port-resolver.sh .env export)
echo $APP_PORT
echo $APP_URL_RESOLVED

# Check sandbox mode
grep "COMPOSE_PROFILES" .env
```

### Manual profile control
```bash
# Enable sandbox in .env
echo "COMPOSE_PROFILES=sandbox" >> .env

# Disable sandbox
sed -i '/COMPOSE_PROFILES/d' .env

# Start with specific profile
docker compose --profile sandbox up -d
```

## Performance Comparison

| Mode | Startup Time | RAM Usage | Disk Usage | Use Case |
|------|-------------|-----------|------------|----------|
| **Lightweight** | ~30s | ~500MB | ~1GB | Web apps, APIs |
| **Full (sandbox)** | ~2min | ~1.5GB | ~3GB | AI features, code execution |
| **Full (browser)** | ~45s | ~800MB+ | ~2GB | Agents with web browsing |
| **Full (voice)** | ~40s * | ~1GB | ~2GB | Local speech in/out |
| **Full (all)** | ~2min | ~3GB+ | ~6GB | Everything enabled |

Choose the mode that best fits your needs and available resources.


## 🔐 Enabling MySQL SSL/TLS (optional)

SSL is **not enabled by default** to keep the repository clean and avoid shipping certificates.

To enable SSL:

1. Copy the example override file:

```bash
cp docker-compose.override.example.yml docker-compose.override.yml
```

2. Put your certificates into:

```
docker/mysql/certs/
  ├── ca.pem
  ├── server-cert.pem
  ├── server-key.pem
  ├── client-cert.pem
  └── client-key.pem
```

3. Start the stack.

The override file is excluded from Git via `.gitignore`, so your real certificates will never be committed.