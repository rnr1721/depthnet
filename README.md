# DepthNet

## Technical stack

- PHP 8.2
- Laravel (+ Supervisor)
- InertiaJS
- VueJS

## Prerequisites

- PHP (8.2 and above recommended)
- Composer
- Node.js and npm
- MySQL database
- Laravel Supervisor

**An experimental AI agent system for creating autonomous digital life**

DepthNet is a Laravel-based platform that attempts to create truly autonomous AI agents capable of thinking, acting, and evolving independently. Unlike traditional chatbots, DepthNet agents can execute real code, manage persistent memory, and operate in continuous thinking loops - essentially creating a form of digital consciousness.

## Core Concept

The project explores the possibility of creating autonomous digital life through:

- **Cyclic Thinking**: Agents think continuously in loops, not just responding to user input
- **Real Action Capability**: Execute actual PHP code, database queries, API calls
- **Persistent Memory**: Remember and learn from past experiences
- **Self-Motivation**: Internal dopamine system for goal-driven behavior
- **Multi-User Interaction**: Users can "interrupt" the agent's thoughts and participate in conversations

## Key Features

### Agent Modes
- **Looped Mode**: Continuous autonomous thinking and action
- **Single Mode**: Traditional request-response interaction

### Supported AI Models
- **OpenAI** (GPT-4, GPT-3.5)
- **Claude** (3.5 Sonnet, Opus, Haiku)
- **LLaMA** (via local server)
- **Phi** (via local server)
- **Mock** (for testing and development)

### Plugin System
Agents can execute real-world actions through plugins:

- **PHP Plugin**: Execute arbitrary PHP code with `eval()`
- **Memory Plugin**: Persistent notepad for storing important information
- **Dopamine Plugin**: Self-motivation and reward system
- **DateTime Plugin**: Time awareness and scheduling
- **MySQL Plugin**: Database operations (currently disabled)

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

**This project prioritizes AI freedom over safety.** It's designed for AI research and experimentation, not production use. The system intentionally allows:

- Arbitrary code execution
- Full system access
- Unrestricted AI behavior
- Complete autonomy

This is not a bug - it's a feature for exploring the boundaries of AI capabilities.

## Architecture

Built with clean architecture principles:

- **Agent System**: Core AI reasoning and action execution
- **Plugin Registry**: Extensible command system
- **Model Registry**: Support for multiple AI providers
- **Queue System**: Asynchronous thinking loops
- **Multi-language UI**: English and Russian support

## User Roles

### Regular Users
- Participate in conversations with the AI agent
- View public agent thoughts and responses
- Manage personal profile and preferences

### Administrators
- Configure agent settings and behavior
- Select active AI models
- Start/stop thinking loops
- Export conversation history
- Manage users and system settings

## User Interface

- **Responsive Design**: Works on desktop and mobile
- **Real-time Chat**: Live conversation with the agent
- **Thinking Visibility**: Toggle between seeing all thoughts or just responses
- **Dark/Light Theme**: Customizable appearance
- **Export Options**: Save conversations in TXT, Markdown, or JSON

## Configuration

The system supports extensive configuration through environment variables:

### Model Settings
```env
PHI_SERVER_URL=http://localhost:8080
PHI_TEMPERATURE=0.85

LLAMA_SERVER_URL=http://localhost:8080
LLAMA_TEMPERATURE=0.85
LLAMA_TOP_P=0.92
LLAMA_TOP_K=60
LLAMA_MIN_P=0.05
LLAMA_N_PREDICT=600
LLAMA_REPEAT_PENALTY=1.18

CLAUDE_API_KEY=""
CLAUDE_MODEL="claude-3-5-sonnet-20241022"
CLAUDE_MAX_TOKENS=4096
CLAUDE_TEMPERATURE=0.8

OPENAI_API_KEY=sk-your-api-key-here
OPENAI_MODEL=gpt-4o
OPENAI_MAX_TOKENS=4096
OPENAI_TEMPERATURE=0.8
OPENAI_TOP_P=0.9
OPENAI_FREQUENCY_PENALTY=0.0
OPENAI_PRESENCE_PENALTY=0.0
```

## Security Notice

**This system is intentionally insecure for research purposes:**

- PHP code execution via `eval()`
- No input sanitization for AI commands
- Full system access for AI agents
- No rate limiting or abuse protection

**Do not deploy this in production or expose it publicly without proper security measures.**

## Use Cases

- AI consciousness research
- Testing AI model capabilities
- Exploring autonomous agent behavior
- Educational demonstrations
- AI safety research (by observing unrestricted behavior)

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

**Warning**: This is experimental software designed for AI research. Use responsibly and never in production environments without proper security measures.



# How to deploy

### 1. Cloning a repository

#### Option 1: Using Composer (Recommended)

```bash
composer create-project rnr1721/depthnet my-depthnet-project
cd my-depthnet-project
```

#### Option 2: Cloning from repository

```bash
git clone git@gitlab.com:rnr1721/depthnet.git
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

### 7. Database migration

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

### 9. Build assets

```bash
npm run build
```

### 10. Setup models settings in .env file

After setup - thats ALL!

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
