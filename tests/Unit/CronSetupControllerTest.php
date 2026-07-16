<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\CronSetupController;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CronSetupControllerTest extends TestCase
{
    #[Test]
    public function production_worker_guide_includes_every_named_queue(): void
    {
        $required = ['default', 'whatsapp', 'broadcast', 'ai', 'social', 'leads', 'automation'];

        $this->assertSame($required, CronSetupController::QUEUE_NAMES);
        $this->assertCount(count(array_unique($required)), CronSetupController::QUEUE_NAMES);
    }
}
