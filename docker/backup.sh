#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

cd "$(dirname "${BASH_SOURCE[0]}")/.."
mkdir -p backups

retention_days="${BACKUP_RETENTION_DAYS:-14}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_file="backups/wisperbot-${timestamp}.sql.gz"
storage_file="backups/wisperbot-storage-${timestamp}.tar.gz"

docker compose exec -T db sh -c 'exec mariadb-dump --single-transaction --quick --routines --triggers -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' | gzip -9 > "$backup_file"
docker compose exec -T app tar -C /var/www/html/storage -czf - app > "$storage_file"
find backups -maxdepth 1 -type f -name 'wisperbot-*.sql.gz' -mtime "+$retention_days" -delete
find backups -maxdepth 1 -type f -name 'wisperbot-storage-*.tar.gz' -mtime "+$retention_days" -delete

echo "$backup_file"
echo "$storage_file"
