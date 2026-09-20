# Production Docker deployment

This stack is intended for a single Ubuntu VPS with host-managed Nginx and
Certbot. Docker publishes the application only on `127.0.0.1:8080`; MariaDB
and Redis are not published to the host or Internet.

The stack uses hosted Pusher for realtime delivery. It does not run Laravel
Reverb and needs no WebSocket proxy rule in Nginx.

## 1. VPS prerequisites

Install Git, Docker Engine, the Docker Compose plugin, curl, and gzip. Apply
Ubuntu security updates and reboot first when `/var/run/reboot-required`
exists.

Clone the private repository over SSH, then enter the checkout:

```bash
git clone git@github.com:YOUR-ORG/YOUR-REPOSITORY.git wisperbot
cd wisperbot
```

## 2. Production environment

Create the untracked environment file:

```bash
cp .env.example .env
```

Because this deployment restores an existing database, copy the exact
`APP_KEY` from the old installation into `.env`. It is needed to decrypt stored
Pusher and integration secrets. Only a brand-new database should generate a
new key, for example:

```bash
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

At minimum, review and set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-DOMAIN
APP_INSTALLED=true

DB_DATABASE=wisperbot_prod
DB_USERNAME=wisperbot
DB_PASSWORD=GENERATE_A_LONG_RANDOM_PASSWORD
DB_ROOT_PASSWORD=GENERATE_A_DIFFERENT_LONG_RANDOM_PASSWORD

REDIS_PASSWORD=GENERATE_A_LONG_RANDOM_PASSWORD
QUEUE_CONNECTION=redis
CACHE_STORE=redis

BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=YOUR_PUSHER_APP_ID
PUSHER_APP_KEY=YOUR_PUSHER_APP_KEY
PUSHER_APP_SECRET=YOUR_PUSHER_APP_SECRET
PUSHER_APP_CLUSTER=YOUR_PUSHER_CLUSTER
VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"

HEALTHZ_TOKEN=GENERATE_A_LONG_RANDOM_TOKEN
SESSION_SECURE_COOKIE=true
```

Also configure SMTP, AI, billing, OAuth, push, SMS, and provider secrets for
the features actually enabled.

Never commit `.env`, `wisperbot.sql`, or a storage archive.

## 3. Import and deploy

The SQL dump does not contain uploaded media, Knowledge Base files, avatars, or
other filesystem objects. Export the previous installation's storage alongside
the database when those files exist:

```bash
tar -C /PATH/TO/OLD/WISPERBOT/storage -czf wisperbot-storage.tar.gz app
```

Place `wisperbot.sql` and, when applicable, `wisperbot-storage.tar.gz` at the
repository root, then run:

```bash
chmod +x deploy.sh docker/backup.sh
./deploy.sh
```

The deployment script:

1. builds immutable PHP and frontend images;
2. starts private MariaDB and Redis services;
3. imports `wisperbot.sql` only when the configured database has zero tables;
4. restores `wisperbot-storage.tar.gz` only when application storage is empty;
5. creates a compressed pre-migration database backup;
6. runs Laravel migrations and `app:deploy:finalize` once for the host Git revision;
7. starts the scheduler and every named queue worker;
8. verifies `http://127.0.0.1:8080/up`.

It never imports over a populated database. Keep `wisperbot.sql` until the
application, uploaded media, credentials, and a backup restore have been
verified. The import files can then be removed from the VPS.

## 4. Host Nginx and Certbot

After the local health check succeeds, use a host Nginx site like this:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name YOUR-DOMAIN;

    client_max_body_size 64m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Enable the site, test Nginx, and let Certbot add HTTPS:

```bash
sudo nginx -t
sudo systemctl reload nginx
sudo certbot --nginx -d YOUR-DOMAIN
```

No Pusher proxy is required. Ensure the Pusher app's cluster and credentials
match `.env`, and do not enable conflicting database-stored Pusher credentials
in the Super Admin settings.

## 5. Updates

For each later release:

```bash
git pull --ff-only
./deploy.sh
```

The production `.env`, MariaDB, Redis, and Laravel storage are persistent and
are not replaced by image rebuilds.

## 6. Backups and operations

Create a database and uploaded-storage backup:

```bash
./docker/backup.sh
```

The `backup` container also creates database and uploaded-storage backups every
24 hours. Backups are written to the ignored `backups/` directory and retained
for 14 days by default. `BACKUP_INTERVAL_SECONDS` and `BACKUP_RETENTION_DAYS`
control those values. Copy important backups off the VPS; a backup stored only
on the same VPS does not protect against disk or account loss.

Useful commands:

```bash
docker compose ps
docker compose logs -f app web
docker compose logs -f queue-ai queue-whatsapp
docker compose exec app php artisan queue:failed
docker compose exec app php artisan schedule:list
```

Do not use `docker compose down -v` in production: `-v` deletes the database,
Redis, and uploaded-storage volumes.
