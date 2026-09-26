<?php

namespace App\Listeners;

use App\Events\SubscriptionStarted;
use App\Services\Marketing\MetaConversions;

/** Reports a customer's first paid subscription or trial to Meta. */
class SendMetaSubscriptionConversion
{
    public function __construct(private readonly MetaConversions $conversions) {}

    public function handle(SubscriptionStarted $event): void
    {
        $this->conversions->subscriptionStarted($event->user, $event->subscription, $event->plan);
    }
}
