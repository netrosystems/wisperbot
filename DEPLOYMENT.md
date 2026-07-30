# WisperBot deployment

Run these steps from the WisperBot application directory after the new code is
merged into `main`:

```bash
php artisan down
git checkout main
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan app:version:bump
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

`app:version:bump` increments the patch version for a normal deployment:
`v1.0.0` becomes `v1.0.1`, then `v1.0.2`, and so on.

For a feature release or major release, use:

```bash
php artisan app:version:bump minor
php artisan app:version:bump major
```

To set an exact version:

```bash
php artisan app:version:bump --set=2.0.0
```

The version is stored in the server's `.env` file and shown at the bottom of
both the client dashboard sidebar and the super-admin sidebar.
