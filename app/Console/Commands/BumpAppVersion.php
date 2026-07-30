<?php

namespace App\Console\Commands;

use App\Services\AppVersionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

class BumpAppVersion extends Command
{
    protected $signature = 'app:version:bump
                            {part=patch : Version segment to increment: major, minor, or patch}
                            {--set= : Set an exact semantic version instead of incrementing}';

    protected $description = 'Increment the application version stored in .env for a deployment';

    public function handle(AppVersionManager $versions): int
    {
        try {
            $previous = $versions->current();
            $version = filled($this->option('set'))
                ? $versions->set((string) $this->option('set'))
                : $versions->bump((string) $this->argument('part'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // A cached config contains the old APP_VERSION. Clear it now so the
        // next request—or the deployment's config:cache command—reads the new
        // value from .env.
        Artisan::call('config:clear');

        $this->info("Application version updated: v{$previous} → v{$version}");
        $this->line('Run php artisan config:cache after the rest of the deployment.');

        return self::SUCCESS;
    }
}
