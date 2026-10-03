<?php

namespace Tests\Unit;

use App\Modules\AI\Services\ChatReplyOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Buttons answer a closed question; an open question is typed by the
 * customer (2026-10-04: "Which country?" came with USA / UK / India).
 */
class ChatReplyChoicesFitTest extends TestCase
{
    private function choices(string $reply, array $labels): array
    {
        return array_column((new ChatReplyOptions)->parse((string) json_encode(['reply' => $reply, 'quick_replies' => $labels]))['quick_replies'], 'label');
    }

    /** @return array<string,array{string}> */
    public static function openQuestions(): array
    {
        return [
            'which' => ['Which country are you looking for an eSIM in?'],
            'what' => ['Great. What is your order date?'],
            'tell me' => ['Could you tell me which phone you use?'],
            'bangla' => ['আপনি কোন দেশের জন্য eSIM খুঁজছেন?'],
            'romanized' => ['Apni kon desher jonno esim chan?'],
            'arabic' => ['أي دولة تريد؟'],
            'hindi' => ['आप किस देश के लिए eSIM चाहते हैं? कौन सा देश?'],
        ];
    }

    #[DataProvider('openQuestions')]
    public function test_an_open_question_gets_no_buttons(string $reply): void
    {
        $this->assertSame([], $this->choices($reply, ['USA', 'UK', 'India']));
    }

    public function test_closed_questions_keep_their_answers(): void
    {
        $this->assertSame(['Yes', 'No'], $this->choices('Plans start from day one. Do you need help with a specific country?', ['Yes', 'No']));
        $this->assertSame(['Basic', 'Pro'], $this->choices('Which plan suits you, Basic or Pro?', ['Basic', 'Pro']));
        $this->assertSame(['হ্যাঁ', 'না'], $this->choices('আপনি কি সাহায্য চান?', ['হ্যাঁ', 'না']));
    }

    public function test_a_choice_worded_as_a_question_is_dropped(): void
    {
        $this->assertSame([], $this->choices('Do you need help with a specific country?', ['Yes, which country?', 'No, just browsing.']));
        $this->assertSame(['Yes, please', 'No, thanks', 'Later'], $this->choices('Would you like the Japan plan?', ['Yes, please', 'No, thanks', 'Later', 'Why?']));
    }
}
