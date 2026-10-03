<?php

namespace Tests\Unit\Inbox;

use App\Modules\Inbox\Services\HandoverRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HandoverRequestTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function requests(): array
    {
        return [
            'english' => ['Can I talk to a person please?'],
            'existing phrase' => ['I want to talk to human please'],
            'bangla' => ['আমি মানুষের সাথে কথা বলতে চাই'],
            'romanized bangla' => ['manusher sathe kotha bolte chai'],
            'arabic' => ['أريد التحدث مع موظف'],
            'hindi' => ['मुझे किसी इंसान से बात करनी है'],
            'spanish' => ['Quiero hablar con una persona'],
            'french' => ['Je veux parler à un conseiller'],
        ];
    }

    #[DataProvider('requests')]
    public function test_a_request_for_a_person_is_recognised_in_many_languages(string $text): void
    {
        $this->assertTrue((new HandoverRequest)->asks($text));
    }

    public function test_ordinary_questions_are_not_requests(): void
    {
        $handover = new HandoverRequest;

        foreach (['Do you deliver to Sylhet?', 'What is your customer care number?', 'Is the agent fee included?', 'yes', 'ডেলিভারি চার্জ কত?'] as $text) {
            $this->assertFalse($handover->asks($text), $text);
        }
    }

    public function test_the_bots_offer_of_a_person_is_recognised(): void
    {
        $handover = new HandoverRequest;

        $this->assertTrue($handover->offersPerson('I do not have a verified answer for that yet. Would you like me to connect you with a person?'));
        $this->assertTrue($handover->offersPerson("Would you like to speak with our team?\n\n1. Yes\n2. No"));
        $this->assertTrue($handover->offersPerson('আপনি কি আমাদের টিমের সাথে কথা বলতে চান?'));
        $this->assertFalse($handover->offersPerson('Would you like the Japan plan?'));
        $this->assertFalse($handover->offersPerson('Is there anything else I can help with?'));
    }
}
