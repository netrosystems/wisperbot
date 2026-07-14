<?php

namespace App\Modules\Leads;

use Illuminate\Support\ServiceProvider;

class LeadsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Keep the historical tables available so removing the retired feature
        // never destroys existing customer data. No client routes are registered.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
