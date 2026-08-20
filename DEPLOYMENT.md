# WisperBot deployment

Run these steps from the WisperBot application directory after the new code is
merged into `main`:

```bash
php artisan down
git checkout main
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan app:deploy:finalize
php artisan up
```

`app:deploy:finalize` automatically increments the patch version once for a
new Git commit: `v1.0.0` becomes `v1.0.1`, then `v1.0.2`, and so on. It also
refreshes Laravel caches and restarts queue workers. Re-running it for the
same commit does not increment the version again.

As a safety net, production also compares the checked-out Git revision on the
first web request after deployment. If the finalizer was accidentally skipped,
that request advances the patch version exactly once. This makes the sidebar
version reliable without incrementing again on refresh or repeated deployment
of the same commit.

Frontend changes still require a current Vite build. Build locally and upload
`public/build`, or run `npm ci && npm run build` on a server with sufficient
Node memory. Pulling PHP/source files alone cannot update the browser UI because
`public/build` is intentionally excluded from Git.

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
