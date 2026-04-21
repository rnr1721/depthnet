# Running DepthNet Behind a Reverse Proxy

This guide covers deploying DepthNet alongside other sites on a server that already has Apache or Nginx running on ports 80/443.

## When You Need This

If your server already hosts other websites (WordPress, Nextcloud, etc.) on standard ports, you can't expose DepthNet directly on port 80/443. Instead:

- DepthNet runs internally on a non-standard port (e.g. `8443` for HTTPS or `8000` for HTTP)
- Your existing web server acts as a reverse proxy, forwarding traffic for `depthnet.yourdomain.com` to that internal port
- From the outside, everything looks like a normal HTTPS site on port 443

## Step 1 — Configure DepthNet

In your `.env` file, set `SSL_MODE` based on your preference:

**Option A — DepthNet handles its own SSL (self-signed)**

This is the default. DepthNet generates a self-signed certificate internally. Apache/Nginx will proxy to it over HTTPS and ignore the certificate warning.

```bash
SSL_MODE=self-signed
APP_URL=https://depthnet.yourdomain.com
HTTPS_PORT=8443
```

**Option B — Disable SSL inside Docker (simpler)**

DepthNet serves plain HTTP internally. The reverse proxy handles HTTPS termination. Cleaner setup, especially if you have a valid certificate on the proxy side.

```bash
SSL_MODE=off
APP_URL=https://depthnet.yourdomain.com
```

> ⚠️ With `SSL_MODE=off`, make sure DepthNet is **not** exposed directly to the internet on port 8000 — only the reverse proxy should be accessible.

After editing `.env`, restart DepthNet:

```bash
./docker/manager.sh restart
```

---

## Option A: Apache Reverse Proxy

### Prerequisites

Enable required Apache modules:

```bash
sudo a2enmod proxy proxy_http ssl
sudo systemctl restart apache2
```

### With SSL_MODE=self-signed (proxy to HTTPS internally)

Create `/etc/apache2/sites-available/depthnet.yourdomain.com.conf`:

```apache
# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName depthnet.yourdomain.com
    Redirect permanent / https://depthnet.yourdomain.com/
</VirtualHost>

# HTTPS VirtualHost
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerAdmin you@example.com
    ServerName depthnet.yourdomain.com

    SSLEngine on
    SSLCertificateFile    /etc/apache2/ssl/depthnet.crt
    SSLCertificateKeyFile /etc/apache2/ssl/depthnet.key

    # Proxy to DepthNet container (which uses self-signed cert internally)
    ProxyPreserveHost On
    ProxyPass / https://localhost:8443/
    ProxyPassReverse / https://localhost:8443/
    SSLProxyEngine On
    SSLProxyVerify none
    SSLProxyCheckPeerCN off
    SSLProxyCheckPeerName off

    ErrorLog /var/log/apache2/depthnet.error.log
    CustomLog /var/log/apache2/depthnet.access.log combined
</VirtualHost>
</IfModule>
```

Generate a self-signed certificate for Apache:

```bash
sudo mkdir -p /etc/apache2/ssl
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/apache2/ssl/depthnet.key \
  -out /etc/apache2/ssl/depthnet.crt \
  -subj "/CN=depthnet.yourdomain.com"
```

### With SSL_MODE=off (proxy to HTTP internally)

```apache
<VirtualHost *:80>
    ServerName depthnet.yourdomain.com
    Redirect permanent / https://depthnet.yourdomain.com/
</VirtualHost>

<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerAdmin you@example.com
    ServerName depthnet.yourdomain.com

    SSLEngine on
    SSLCertificateFile    /etc/apache2/ssl/depthnet.crt
    SSLCertificateKeyFile /etc/apache2/ssl/depthnet.key

    ProxyPreserveHost On
    ProxyPass / http://localhost:8000/
    ProxyPassReverse / http://localhost:8000/

    ErrorLog /var/log/apache2/depthnet.error.log
    CustomLog /var/log/apache2/depthnet.access.log combined
</VirtualHost>
</IfModule>
```

### Enable and reload

```bash
sudo a2ensite depthnet.yourdomain.com.conf
sudo apache2ctl configtest
sudo systemctl restart apache2
```

---

## Option B: Nginx Reverse Proxy

### With SSL_MODE=self-signed

Create `/etc/nginx/sites-available/depthnet.yourdomain.com`:

```nginx
server {
    listen 80;
    server_name depthnet.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name depthnet.yourdomain.com;

    ssl_certificate     /etc/nginx/ssl/depthnet.crt;
    ssl_certificate_key /etc/nginx/ssl/depthnet.key;

    location / {
        proxy_pass https://localhost:8443;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Ignore self-signed cert from DepthNet container
        proxy_ssl_verify off;
    }
}
```

Generate a self-signed certificate for Nginx:

```bash
sudo mkdir -p /etc/nginx/ssl
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/nginx/ssl/depthnet.key \
  -out /etc/nginx/ssl/depthnet.crt \
  -subj "/CN=depthnet.yourdomain.com"
```

### With SSL_MODE=off

```nginx
server {
    listen 80;
    server_name depthnet.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name depthnet.yourdomain.com;

    ssl_certificate     /etc/nginx/ssl/depthnet.crt;
    ssl_certificate_key /etc/nginx/ssl/depthnet.key;

    location / {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Enable and reload

```bash
sudo ln -s /etc/nginx/sites-available/depthnet.yourdomain.com /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## Step 2 — Replace Self-Signed Certificate with Let's Encrypt

Once your DNS is pointing to the server and propagated, replace the self-signed cert with a real one using Certbot.

### Apache

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d depthnet.yourdomain.com
```

Certbot will automatically update your Apache config and set up auto-renewal.

### Nginx

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d depthnet.yourdomain.com
```

### Verify auto-renewal

```bash
sudo certbot renew --dry-run
```

---

## Troubleshooting

**Browser shows a different site instead of DepthNet**

Make sure Apache/Nginx restarted fully (not just reloaded) after adding the config:

```bash
sudo systemctl restart apache2   # or nginx
```

Verify the virtual host is recognized:

```bash
apache2ctl -S | grep depthnet    # Apache
nginx -T | grep depthnet         # Nginx
```

**Infinite redirect loop in browser**

This happens when DepthNet redirects to HTTPS but Apache proxies to `https://localhost:8443` which redirects again. Fix: either use `SSL_MODE=off` with HTTP proxy, or make sure the Apache config has `SSLProxyVerify none` and related directives.

**curl works but browser doesn't**

Usually a browser DNS cache issue. Hard refresh with `Ctrl+Shift+R`, or open in incognito mode.

**DNS not resolving yet**

While waiting for DNS propagation, add a temporary entry to your local `/etc/hosts`:

```
# Linux / macOS
YOUR_SERVER_IP depthnet.yourdomain.com

# Windows: C:\Windows\System32\drivers\etc\hosts
YOUR_SERVER_IP depthnet.yourdomain.com
```

On MikroTik routers, add a static DNS entry via **IP → DNS → Static** and point your workstation's DNS to the router IP instead of 8.8.8.8.