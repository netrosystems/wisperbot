#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

cd "$(dirname "${BASH_SOURCE[0]}")"

if [[ ! -f .env ]]; then
    echo "Missing .env. Copy .env.example to .env and configure production values first." >&2
    exit 1
fi

for command_name in docker curl git gzip; do
    command -v "$command_name" >/dev/null 2>&1 || {
        echo "Required command not found: $command_name" >&2
        exit 1
    }
done

docker compose version >/dev/null
chmod 600 .env
deployment_revision="$(git rev-parse HEAD)"

echo "Building production images..."
docker compose build --pull

echo "Starting MariaDB and Redis..."
docker compose up -d db redis

echo "Waiting for MariaDB..."
for attempt in {1..60}; do
    if docker compose exec -T db sh -c 'mariadb-admin ping -h 127.0.0.1 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --silent' >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" -eq 60 ]]; then
        echo "MariaDB did not become ready." >&2
        exit 1
    fi
    sleep 2
done

table_count="$(docker compose exec -T db sh -c 'mariadb -N -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"' | tr -d '\r[:space:]')"

if [[ "$table_count" == "0" ]]; then
    if [[ ! -f wisperbot.sql ]]; then
        echo "Database is empty and wisperbot.sql is missing." >&2
        echo "Place the existing dump at ./wisperbot.sql and run ./deploy.sh again." >&2
        exit 1
    fi

    echo "Importing wisperbot.sql into the empty database..."
    docker compose exec -T db sh -c 'exec mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < wisperbot.sql
    echo "Database import completed. Keep the dump until the deployment and backup are verified."
else
    echo "Database already contains $table_count tables; SQL import skipped."
fi

storage_file_count="$(docker compose run --rm --no-deps -T app sh -c 'find storage/app -type f ! -name .gitignore | wc -l' | tr -d '\r[:space:]')"
if [[ "$storage_file_count" == "0" && -f wisperbot-storage.tar.gz ]]; then
    echo "Restoring uploaded/private files from wisperbot-storage.tar.gz..."
    docker compose run --rm --no-deps -T app tar -C /var/www/html/storage -xzf - < wisperbot-storage.tar.gz
elif [[ "$storage_file_count" == "0" ]]; then
    echo "WARNING: No storage archive found. Database rows that reference uploaded files may have missing media." >&2
elif [[ -f wisperbot-storage.tar.gz ]]; then
    echo "Storage already contains files; storage archive import skipped."
fi

mkdir -p backups
backup_file="backups/wisperbot-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
echo "Creating pre-migration database backup: $backup_file"
docker compose exec -T db sh -c 'exec mariadb-dump --single-transaction --quick --routines --triggers -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' | gzip -9 > "$backup_file"

echo "Enabling maintenance mode..."
if [[ -n "$(docker compose ps --status running -q app)" ]]; then
    docker compose exec -T --user www-data app php artisan down || true
else
    docker compose run --rm app php artisan down || true
fi

echo "Starting the new application image for migration..."
docker compose up -d app

echo "Running database migrations and deployment finalizer..."
docker compose exec -T --user www-data app php artisan migrate --force
docker compose exec -T --user www-data app php artisan app:deploy:finalize --revision="$deployment_revision"

echo "Starting all application processes..."
docker compose up -d --force-recreate --no-deps --remove-orphans \
    app \
    web \
    backup \
    scheduler \
    queue-default \
    queue-whatsapp \
    queue-broadcast \
    queue-ai \
    queue-social \
    queue-leads \
    queue-automation \
    queue-channel-health
docker compose exec -T --user www-data app php artisan optimize
docker compose exec -T --user www-data app php artisan up

echo "Waiting for the local HTTP endpoint..."
app_http_port="$(grep -E '^APP_HTTP_PORT=' .env | tail -n1 | cut -d= -f2- | tr -d '\r\"' || true)"
app_http_port="${app_http_port:-8080}"
for attempt in {1..30}; do
    if curl --fail --silent --show-error "http://127.0.0.1:${app_http_port}/up" >/dev/null; then
        break
    fi
    if [[ "$attempt" -eq 30 ]]; then
        echo "Application health check failed. Run: docker compose logs app web" >&2
        exit 1
    fi
    sleep 2
done

echo
echo "Deployment completed successfully."
echo "Application: http://127.0.0.1:${app_http_port}"
echo "Backup: $backup_file"
echo "Next: configure host Nginx and Certbot, then verify the HTTPS domain."
