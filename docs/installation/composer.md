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

Important! Don't forget to configure plugins in the "plugins" section of the admin panel. Each plugin has its own individual settings.

⚠️ **IMPORTANT:** Change the default password after first login!

#№ Setup plugins config in admin panel (Important!)

Review the plugin settings in the corresponding section of the admin panel and customize the environment according to your needs.
