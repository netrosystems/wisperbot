<?php

namespace Tests\Feature\MarketingSuite;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Leads\Jobs\ScrapeLeadsJob;
use App\Modules\Leads\Models\LeadScrapeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function client_lead_scraper_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('client.leads.index'));
        $this->assertFalse(Route::has('client.leads.scrape'));
        $this->assertFalse(Route::has('client.leads.push-to-contacts'));
        $this->assertFalse(Route::has('client.leads.destroy'));
        $this->post('/app/leads/scrape')->assertNotFound();
    }

    #[Test]
    public function google_places_is_not_an_admin_integration_provider(): void
    {
        $this->assertNotContains('google_places', IntegrationConfig::PROVIDERS);
        $this->assertArrayNotHasKey('google_places', IntegrationConfig::FIELDS);

        $this->withoutMiddleware()
            ->get(route('admin.integrations.edit', 'google_places'))
            ->assertNotFound();
    }

    #[Test]
    public function previously_queued_scrape_is_cancelled_without_calling_places(): void
    {
        $scrape = LeadScrapeJob::create([
            'workspace_id' => 1,
            'keyword' => 'restaurants',
            'location' => 'Dhaka',
            'status' => 'pending',
        ]);

        (new ScrapeLeadsJob($scrape->id))->handle();

        $this->assertDatabaseHas('lead_scrape_jobs', [
            'id' => $scrape->id,
            'status' => 'failed',
            'error' => 'Lead scraper has been retired.',
        ]);
    }
}
