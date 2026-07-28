# Installation Guide

## Method 1: Docker (recommended for quick start)

### Requirements
- Docker 24+ with Compose v2
- 2 GB free RAM
- 5 GB free disk

### Steps

```bash
git clone https://github.com/monajiane/xerex-panel.git
cd xerex-panel

cp .env.example .env

# Boot all services
docker compose up -d

# Wait ~30s for DB to be ready, then:
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link

# Open the panel
open http://localhost:8000
```

**Default admin login:**
```
admin@xerex.local / password
```

> ⚠️ Change this password immediately in production.

---

## Method 2: Manual install (bare metal / VM)

### Requirements
- **OS**: Ubuntu 22.04+ / Debian 12+ / RHEL 9+
- **PHP**: 8.3+ with extensions: `pdo`, `pdo_pgsql` (or `pdo_mysql`), `intl`, `zip`, `bcmath`, `opcache`, `pcntl`, `sockets`
- **Composer**: 2.7+
- **Node.js**: 20+ (for asset building)
- **Database**: PostgreSQL 14+ OR MariaDB 10.6+
- **Redis**: 7+
- **Web server**: Nginx 1.27+ (or Apache 2.4+)

### Steps

#### 1. Install system packages

```bash
# Ubuntu 22.04+
sudo apt update
sudo apt install -y software-properties-common ca-certificates lsb-release apt-transport-https

sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y \
    php8.3 php8.3-{cli,fpm,common,mysql,xml,zip,curl,mbstring,bcmath,intl,opcache,pcntl,sockets,readline} \
    nginx certbot python3-certbot-nginx \
    redis-server \
    postgresql \
    nodejs npm \
    git unzip supervisor

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

#### 2. Create the database

```bash
sudo -u postgres psql
```

```sql
CREATE USER xerex WITH PASSWORD 'change_me_strong';
CREATE DATABASE xerex_panel OWNER xerex;
GRANT ALL PRIVILEGES ON DATABASE xerex_panel TO xerex;
\q
```

#### 3. Install the panel

```bash
sudo mkdir -p /var/www/xerex
sudo chown $USER:www-data /var/www/xerex
cd /var/www/xerex

git clone https://github.com/monajiane/xerex-panel.git .

cp .env.example .env
$EDITOR .env  # Set DB_*, REDIS_*, APP_URL, etc.

composer install --no-dev --optimize-autoloader
npm install
npm run build

php artisan key:generate
php artisan migrate --seed --force
php artisan storage:link

# Set permissions
sudo chown -R www-data:www-data /var/www/xerex
sudo find /var/www/xerex -type d -exec chmod 755 {} \;
sudo find /var/www/xerex -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/xerex/storage /var/www/xerex/bootstrap/cache
```

#### 4. Configure Nginx

```nginx
# /etc/nginx/sites-available/xerex.conf
server {
    listen 80;
    server_name panel.example.com;
    root /var/www/xerex/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/xerex.conf /etc/nginx/sites-enabled/
sudo certbot --nginx -d panel.example.com
sudo systemctl reload nginx
```

#### 5. Set up the queue worker (Supervisor)

```ini
# /etc/supervisor/conf.d/xerex.conf
[program:xerex-horizon]
process_name=%(program_name)s
command=php /var/www/xerex/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/xerex-horizon.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start xerex-horizon:*
```

#### 6. Schedule (cron for SSL renewal etc.)

```bash
sudo crontab -u www-data -e
```

```
* * * * * cd /var/www/xerex && php artisan schedule:run >> /dev/null 2>&1
```

---

## Production Hardening

- [ ] Change `APP_DEBUG=false`
- [ ] Set strong `APP_KEY` (`php artisan key:generate --force`)
- [ ] Set `XEREX_MASTER_TOKEN` to a long random string
- [ ] Set `XEREX_EDGE_HMAC_SECRET` to a long random string
- [ ] Enable firewall (only 22, 80, 443 inbound)
- [ ] Use a real domain with valid TLS cert
- [ ] Configure automated DB backups (`pg_dump` daily)
- [ ] Configure Redis persistence
- [ ] Set up log rotation (`/etc/logrotate.d/xerex`)
- [ ] Set up monitoring (e.g., Prometheus node_exporter)

## Upgrading

```bash
cd /var/www/xerex
git pull
composer install --no-dev --optimize-autoloader
npm install && npm run build
php artisan migrate --force
php artisan optimize:clear
sudo supervisorctl restart xerex-horizon:*
```
