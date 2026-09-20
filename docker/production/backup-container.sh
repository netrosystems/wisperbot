#!/bin/sh
set -eu
set -o pipefail
umask 077

mkdir -p /backups

while true; do
    timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
    database_file="/backups/wisperbot-${timestamp}.sql.gz"
    storage_file="/backups/wisperbot-storage-${timestamp}.tar.gz"

    if mariadb-dump \
        --host="${DB_HOST:-db}" \
        --user="$DB_USERNAME" \
        --password="$DB_PASSWORD" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        "$DB_DATABASE" | gzip -9 > "$database_file"; then
        tar -C /storage -czf "$storage_file" app
        find /backups -maxdepth 1 -type f -name 'wisperbot-*.sql.gz' -mtime "+${BACKUP_RETENTION_DAYS:-14}" -delete
        find /backups -maxdepth 1 -type f -name 'wisperbot-storage-*.tar.gz' -mtime "+${BACKUP_RETENTION_DAYS:-14}" -delete
        echo "Backup completed: $timestamp"
    else
        rm -f "$database_file"
        echo "Database backup failed: $timestamp" >&2
    fi

    sleep "${BACKUP_INTERVAL_SECONDS:-86400}"
done
