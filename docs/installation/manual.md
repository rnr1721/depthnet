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

After setup - thats almost ALL!
Your initial credentials for login:

- **login:** admin@example.com
- **password:** admin123

### 13. Setup plugins config in admin panel (Important!)

Review the plugin settings in the corresponding section of the admin panel and customize the environment according to your needs.

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
