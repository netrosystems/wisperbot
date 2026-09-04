<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Services\Llm\LlmResponse;

class AiCreditReservation
{
    public function __construct(
        public readonly AiCreditLedger $ledger,
        public readonly ?LlmResponse $replayedResponse = null,
    ) {}
}
