<?php

namespace App\Console\Commands;

use App\Services\AppVersionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

class FinalizeDeployment extends Command
{
    protected $signature = 'app:deploy:finalize
                            {--revision= : Git revision being deployed; automatically detected when omitted}';

    protected $description = 'Finalize a deployment, automatically advancing the version once per new Git revision';

    public function handle(AppVersionManager $versions): int
    {
        $revision = trim((string) $this->option('revision'));

        if ($revision === '') {
            $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
            $process->run();
            $revision = $process->isSuccessful() ? trim($process->getOutput()) : '';
        }

        if ($revision === '') {
            $this->error('Could not determine the deployed Git revision. Run this from the Git checkout or pass --revision=<commit>.');

            return self::FAILURE;
        }

        $release = $versions->bumpForRelease($revision);

        Artisan::call('optimize:clear');
        Artisan::call('config:cache');
        Artisan::call('route:cache');
        Artisan::call('view:cache');
        Artisan::call('queue:restart');

        if ($release['changed']) {
            $this->info("Deployment finalized. Application version is now v{$release['version']}.");
        } else {
            $this->info("Deployment finalized. This revision was already released as v{$release['version']}; version unchanged.");
        }

        return self::SUCCESS;
    }
}
