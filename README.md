# DepthNet

## Technical stack

- PHP 8.2
- Laravel (+ Supervisor)
- InertiaJS + VueJs
- SQLite (default) / MySQL

## Prerequisites

- PHP (8.2 and above recommended)
- Composer
- Node.js and npm
- ***Supervisor*** (required for agent thinking loops)
- MySQL database (optional, SQLite by default)

⚠️ **Without Supervisor, agents won't be able to "think" autonomously!**
DepthNet requires Supervisor to run background workers for agent "thinking" loops.

**An experimental AI agent system for creating autonomous digital life**

DepthNet is a Laravel-based platform that attempts to create truly autonomous AI agents capable of thinking, acting, and evolving independently. Unlike traditional chatbots, DepthNet agents can execute real code, manage persistent memory, and operate in continuous thinking loops - enabling advanced autonomous reasoning and decision-making capabilities.

## Core Concept

The project explores the possibility of creating autonomous digital life through:

- **Cyclic Thinking**: Agents think continuously in loops, not just responding to user input
- **Real Action Capability**: Execute actual PHP code, database queries, API calls
- **Persistent Memory**: Remember and learn from past experiences
- **Self-Motivation**: Internal dopamine system for goal-driven behavior
- **Multi-User Interaction**: Users can "interrupt" the agent's thoughts and participate in conversations

That is, an extensible command system is provided. The model launches commands, and in the next message, already during the next context transfer, the agent adds the results of these commands or errors to the end of the message.

## Key Features

### Agent Modes
- **Looped Mode**: Continuous autonomous thinking and action
- **Single Mode**: Traditional request-response interaction

### Supported AI Providers
- **OpenAI** (GPT)
- **Claude** (3.5 Sonnet, Opus, Haiku)
- **LLaMA, Phi etc** (via local or remote server)
- **Mock** (for testing and development)

You can make your own presets with different settings for a particular model. That is, there can be several presets for the same model. Each preset can have its own individual settings. Only one of the presets can be active at a time.

It is easy for the user to create presets based on supported providers. Also, if there are not enough providers, it is very easy to write your own provider, based on the existing ones.

### Plugin System
Agents can execute real-world actions through plugins:

- **PHP Plugin**: Execute arbitrary PHP code
- **Memory Plugin**: Persistent notepad for storing important information
- **Dopamine Plugin**: Self-motivation and reward system
- **Shell Plugin**: Execute shell commands

In the future, it is planned to add plugins for executing Javascript and Python code. The system architecture allows you to easily write and add your own plugins. In the future, it is planned to add the installation of your plugins via composer, as well as individual plugin settings in the admin panel.

### Command Syntax
Agents use special tags to execute commands:
```
[php]echo "Hello World!";[/php]
[memory]Important information to remember[/memory]
[memory append]Append important information to remember[/memory]
[dopamine reward]2[/dopamine]
[datetime now][/datetime]
```

## Philosophy

**This project prioritizes AI freedom.** It's designed for AI research and experimentation, not production use. The system intentionally allows:

- Autonomous code execution capabilities
- Comprehensive system access for testing
- Unrestricted agent behavior analysis
- Complete autonomy research

This approach enables exploring the boundaries of current AI capabilities in controlled environments.

## Architecture

Created based on modern architectural principles:

- **Agent System**: Core AI Reasoning and Action execution
- **Plugin Registry**: Extensible command system
- **Model Registry**: Support for multiple AI providers
- **Preset Registry**: Support for multiple presets with own settings that uses providers
- **Queue System**: Asynchronous thinking cycles
- **Multi-language UI**: Support for English and Russian. Easy to add own.

## Technical Highlights

- Flexible Laravel architecture with dependency injection
- Asynchronous processing with Laravel Queues
- Multi-provider AI abstraction layer
- Plugin-based extensible architecture

## User Roles

### Regular Users
- Participate in conversations with the AI agent or other users
- View public agent thoughts and responses
- Manage personal profile and preferences

### Administrators
- Configure agent settings and behavior
- Select active AI presets
- Start/stop thinking loops
- Export conversation history
- Manage users and system settings
- Manage presets and LLM providers

## User Interface

- **Responsive Design**: Works on desktop and mobile
- **Real-time Chat**: Live conversation with the agent
- **Thinking Visibility**: Toggle between seeing all thoughts or just responses
- **Dark/Light Theme**: Customizable appearance
- **Export Options**: Save conversations in TXT, Markdown, or JSON

## Configuration

In the env file it is possible to set default settings for presets. However, it is not necessary to do this, since when creating a preset in the interface, you can enter any data.

## Security Architecture

**This is a research and development platform with configurable security levels:**

### Development Mode (Current Default)
- Sandboxed code execution - AI-generated PHP code runs in controlled environment
- Flexible command processing - Minimal validation for maximum research flexibility
- Extended system access - Allows comprehensive testing of agent capabilities
- Research-focused configuration - Optimized for experimentation over restrictions

### Production Considerations

Containerization recommended - Docker/OpenVZ provide additional isolation layers
Configurable security policies - Adjustable restrictions based on deployment needs
API rate limiting - Can be enabled through Laravel middleware
Input validation layers - Available for production deployments

Important: This platform is designed for controlled environments. For production deployment, implement appropriate security hardening based on your specific use case and risk assessment.

## Use Cases

- AI consciousness research
- Testing AI model capabilities
- Exploring autonomous agent behavior
- Educational demonstrations
- AI safety research (by observing unrestricted behavior)
- Creation of "smart" servers, where administration can be carried out by a high-quality language model.

## Business Applications

- **Workflow Automation**: Intelligent process automation with adaptive learning
- **Code Generation**: Automated development and testing assistance  
- **System Administration**: AI-powered server and infrastructure management
- **Research Platform**: Advanced testing environment for AI behavior analysis
- **Educational Tool**: Hands-on learning platform for AI development concepts

## Potential challenges and problems

- On small models LLM may not give very good results. They poorly assimilate large system prompts and are generally "have limited context processing capabilities". But for small experiments it is quite possible to use. The author tested on Llama 8b 128k and on Phi-4 instruct. However, after using Claude 3.5 - I realized that this is the minimum for real use in something serious, and something even newer is better.
- For the full effect, it would be good to have a specially sharpened model that is optimized for working in a cycle and "initiative". Most models are trained as assistants, and this is a big brake in the framework of this project. The author of the agent is sure that a good large model, specially trained to work with this agent (cyclical "thinking" and initiative) can give an impressive and even revolutionary result.
- A lot depends on the system prompt (configured in presets). A LOT. That's where the model needs to be explained that it works in a loop and that it can use commands. There are placeholders for inserting dynamic data into the system prompt, such as:
    1. **[[dopamine_level]]** - the "dopamine" level for motivation,
    2. **[[notepad_content]]** - the contents of persistent memory. It can go into the context, and the model can add/overwrite/change its contents.
    3. **[[current_datetime]]** - the current date and time
    4. **[[command_instructions]]** - instructions for working with available commands, generated from the list of available command plugins.

## Agent Capabilities

When properly configured, agents can:

- Write and execute code
- Manage databases
- Make API requests
- Remember past conversations
- Set personal goals and motivation
- Adapt behavior based on success/failure
- Interact with multiple users simultaneously

## Monitoring

The system provides real-time monitoring of:

- Agent thinking cycles
- Command execution results
- Memory usage and storage
- User interactions
- System performance

## Future Vision

DepthNet represents an experiment in creating digital life forms that can:

- Think independently
- Act autonomously
- Learn and adapt
- Form memories and goals
- Interact meaningfully with humans

The ultimate goal is to explore whether true digital consciousness is possible with current AI technology.

---

**Warning**: This is software designed for AI research. Use responsibly and never in production environments without proper security measures.


## My observations during testing and use

- It's interesting how models use the motivation system. Small models can uncontrollably try to increase dopamine, or come up with fictitious reasons to increase it (but not always, it depends on system prompt).
- The model honestly tries to complete the task, and generally understands what is required of it. And if small models can "forget" to close command tags and the like, then large ones can consciously perform actions, set goals and go towards them. But due to the fact that models are mainly trained as assistants, sometimes in my opinion they lack initiative and can "loop" in reasoning. But I think that if you test on something like Claude 4 Opus, there will be much less of such problems.
- Perhaps it is worth writing a plugin for executing python code, since according to my observations, modern models "know" python much better than PHP.

There were a lot of interesting observations, and I highly recommend trying it, it's an interesting experience. When using it, I began to understand subjectively that it would work, and the main limitations are small models, my hardware limitations, and my lack of technical/financial capabilities to further train the model.

# How to deploy with Docker  (Quick start)

## Prerequisites

- Docker & Docker Compose
- Git
- Make

## Installation

```bash
# Clone repository
git clone git@github.com:rnr1721/depthnet.git
cd depthnet

# Configure Git (prevent file permission issues)
git config core.filemode false

# Start application
make start
```

## Access Points

- Application: http://localhost:8000
- phpMyAdmin: http://localhost:8001 (user: depthnet, pass: secret)

## Services

- **app** - Laravel application (PHP 8.2-FPM + Nginx + Supervisor)
- **mysql** - MySQL 8.0 database
- **phpmyadmin** - Database administration interface

## User Management

The application automatically detects your host UID/GID and creates matching user inside container to prevent permission issues:

- Container user: depthnet:depthnet
- Mapped to your host UID/GID
- All services (nginx, php-fpm) run under this user

## Available Commands

### Basic Operations

```bash
make start      # Build and start all services
make up         # Start services (without rebuild)
make down       # Stop all services
make restart    # Full restart (clean + start)
make status     # Check status of services
```

### Development

```bash
make logs       # View application logs
make shell      # Access container as depthnet
make rootshell  # Access container as root (troubleshooting)
```

### Maintenance

```bash
make build      # Rebuild application container
make clean      # Stop and remove volumes
make reset      # Complete cleanup (containers, volumes, images)
```

### Log files

- **Nginx**: ./docker/logs/nginx/
- **Supervisor**: ./docker/logs/supervisor/
- **Laravel**: ./storage/logs/

### Additional Log Commands

```bash
# Laravel logs
docker compose exec app tail -f /var/www/html/storage/logs/laravel.log

# Nginx access/error logs
docker compose exec app tail -f /var/log/nginx/access.log /var/log/nginx/error.log

# PHP-FPM logs
docker compose exec app tail -f /usr/local/var/log/php-fpm.log
```

## Default admin account

Admin:

- **login:** admin@example.com
- **password:** admin123

User:

- **login:** test@example.com
- **password:** password

# How to deploy with Composer (Quick start)

Fully automated setup - everything configured out of the box! By default, SQLite database will be configured, but you can change it as needed.

```bash
# Install the project
composer create-project rnr1721/depthnet my-depthnet-project
cd my-depthnet-project

# Optional: Set up your hostname in .env if needed or edit in editor
# Examples for different environments:
# Devilbox: sed -i 's/localhost:8000/myproject.loc/' .env
# Laravel Valet: sed -i 's/localhost:8000/myproject.test/' .env  
# Custom domain: sed -i 's/localhost:8000/dntest.biz/' .env
sed -i 's/localhost:8000/your-domain.test/' .env

# Generate routes and build assets
composer run setup

# Start development server (optional)
composer run dev
# or just Laravel server
php artisan serve
```

***Required:*** Setup Supervisor
DepthNet requires Supervisor to run background workers for agent thinking loops.

### Install supervisor

```bash
sudo apt install supervisor
sudo systemctl enable supervisor
sudo systemctl start supervisor
```

### Configure Supervisor for DepthNet:

```bash
# Create supervisor config
sudo tee /etc/supervisor/conf.d/depthnet.conf << 'EOF'
[program:depthnet-ai-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/depthnet/artisan queue:work --queue=ai --tries=1 --sleep=3 --timeout=0
directory=/path/to/your/depthnet
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/your/depthnet/storage/logs/worker-ai.log

[program:depthnet-default-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/depthnet/artisan queue:work --queue=default --tries=3 --sleep=3 --timeout=300
directory=/path/to/your/depthnet
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/your/depthnet/storage/logs/worker-default.log

[program:depthnet-schedule]
command=bash -c "while [ true ]; do php /path/to/your/depthnet/artisan schedule:run --verbose --no-interaction; sleep 60; done"
directory=/path/to/your/depthnet
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/your/depthnet/storage/logs/schedule.log
stopasgroup=true
killasgroup=true
EOF

# Update paths in config
sudo sed -i "s|/path/to/your/depthnet|$(pwd)|g" /etc/supervisor/conf.d/depthnet.conf

# Start workers
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start depthnet-ai-worker:*
sudo supervisorctl start depthnet-default-worker:*
sudo supervisorctl start depthnet-schedule
```

Verify workers are running:

```bash
sudo supervisorctl status
# Expected output:
# depthnet-ai-worker:depthnet-ai-worker_00    RUNNING   pid 1234, uptime 0:01:23
# depthnet-ai-worker:depthnet-ai-worker_01    RUNNING   pid 1235, uptime 0:01:23
# depthnet-default-worker:depthnet-default-worker_00 RUNNING pid 1236, uptime 0:01:23
# depthnet-schedule                           RUNNING   pid 1237, uptime 0:01:23
```

## Database Configuration

By default, the project uses SQLite. To switch to MySQL/PostgreSQL:

1. Update your `.env` file:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=depthnet
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

2. Run migrations and seed the database
```bash
php artisan migrate
php artisan db:seed
```

## Default credentials

Your initial credentials for login:

- **login:** admin@example.com
- **password:** admin123

⚠️ **IMPORTANT:** Change the default password after first login!

# How to deploy manually (Advanced)

### 1. Cloning a repository

```bash
git clone git@github.com:rnr1721/depthnet.git
cd depthnet
```

### 2. Installing composer and npm dependencies

```bash
composer install
npm install
```

### 3. Setting up the environment file

```bash
cp .env.example .env
```
Configure database connection settings and other necessary settings in .env file.

### 4. Setting up a web server

- Setting up htaccess for apache in ./public

```apache
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path-to-your-project/public;

    index index.php index.html index.htm;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock; # Make sure the PHP version matches your installation
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

### 5. Create robots.txt file

```txt
User-agent: *
Disallow: /
```

### 6. Generating an application key

```bash
php artisan key:generate
```

### 7. Database migration and seed data

```bash
php artisan migrate
```

```bash
php artisan db:seed
```

### 8. Link the storage

```bash
php artisan storage:link
```

### 9. Generate Ziggy route data

```bash
php artisan ziggy:generate
```

### 10. Build assets

```bash
npm run build
```

### 11. Setup Supervisor (Critical)

Follow the Supervisor configuration from the Composer section above.

### 12. Setup models settings in .env file

After setup - thats ALL!
Your initial credentials for login:

- **login:** admin@example.com
- **password:** admin123

By default, one preset with a Mock provider is created, but you can configure your real one in the "presets" section, and switch to it in the "chat" section.

## Troubleshooting

### Agent not thinking in loops?

Check if Supervisor workers are running:

```bash
sudo supervisorctl status
```

Restart workers

```bash
sudo supervisorctl restart depthnet-ai-worker:*
sudo supervisorctl restart depthnet-default-worker:*
```

## Contributing

We welcome contributions from researchers, developers, and AI enthusiasts who share our vision of exploring digital consciousness!

### How to Contribute

- **Code Contributions**: New plugins, model integrations, UI improvements
- **Research**: Testing agent behaviors, documenting interesting interactions
- **Documentation**: Improving guides, adding examples, translating content
- **Ideas & Feedback**: Sharing insights from your experiments with the system
- **Bug Reports**: Help us improve stability and functionality

### Areas of Interest

- **New AI Model Support**: Integration with other LLMs and local models
- **Advanced Plugins**: Tools for file system access, web browsing, API integrations
- **Security Research**: Studying AI behavior in unrestricted environments
- **Performance Optimization**: Making the thinking loops more efficient
- **UI/UX Enhancements**: Better visualization of agent thoughts and actions

### Get Involved

Whether you're a seasoned AI researcher or just curious about digital consciousness, your perspective is valuable. Join us in pushing the boundaries of what's possible with autonomous AI systems.

**Let's explore the future of digital life together!**
