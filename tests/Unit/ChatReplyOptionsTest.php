<?php

namespace Tests\Unit;

use App\Modules\AI\Services\ChatReplyOptions;
use PHPUnit\Framework\TestCase;

class ChatReplyOptionsTest extends TestCase
{
    public function test_structured_choices_keep_a_readable_legacy_fallback(): void
    {
        $result = (new ChatReplyOptions)->parse('{"reply":"Which platform?","quick_replies":["iOS app","Android app","Both"]}');
        $this->assertSame('Which platform?', $result['display_body']);
        $this->assertStringContainsString('1. iOS app', $result['reply']);
        $this->assertSame(['id' => 'qr_2', 'label' => 'Android app'], $result['quick_replies'][1]);
    }

    public function test_choices_are_bounded_deduplicated_and_text_only(): void
    {
        $service = new ChatReplyOptions;
        $choices = $service->sanitize(['<img src=x>', 'https://evil.test', ['label' => 'iOS', 'action' => 'pay'], 'IOS', str_repeat('x', 61), 'Android', 'Both', 'Fourth']);
        $this->assertSame(['iOS', 'Android', 'Both'], array_column($choices, 'label'));
        $this->assertSame(['id', 'label'], array_keys($choices[0]));
        $this->assertSame([], $service->sanitize('invalid'));
    }

    public function test_plain_text_and_empty_choices_remain_compatible(): void
    {
        $service = new ChatReplyOptions;
        $this->assertSame('Hello', $service->parse('Hello')['reply']);
        $this->assertSame([], $service->parse('{"reply":"Hello","quick_replies":[]}')['quick_replies']);
        $this->assertSame([], $service->parse('{"reply":"Hello","quick_replies":["One"]}')['quick_replies']);
        $this->assertSame('হ্যালো', $service->parse('{"reply":"হ্যালো","quick_replies":[]}')['reply']);
    }

    public function test_malformed_or_truncated_structured_output_is_rejected(): void
    {
        $service = new ChatReplyOptions;
        foreach (['', '{"reply":"Hello', '{"quick_replies":["One","Two"]}', '{"reply":123}', "```json\n{\"reply\":"] as $content) {
            $this->assertNull($service->parse($content));
        }
    }

    public function test_esim_question_recovers_explicit_choices_from_plain_text_and_json(): void
    {
        $reply = 'Does your phone support eSIM? Please check in the Telzen App under Home > eSIM check and let me know what it says: Supported or Not Supported.';
        foreach ([$reply, json_encode(['reply' => $reply, 'quick_replies' => []])] as $content) {
            $result = (new ChatReplyOptions)->parse($content, true);
            $this->assertSame(['Supported', 'Not supported'], array_column($result['quick_replies'], 'label'));
            $this->assertSame($reply, $result['display_body']);
            $this->assertStringContainsString('2. Not supported', $result['reply']);
        }
    }

    public function test_yes_no_recovery_is_conservative_and_can_be_disabled(): void
    {
        $service = new ChatReplyOptions;
        foreach (['Does your phone support eSIM?', 'Open Settings. Can you see the eSIM?', 'Please reply Yes or No.'] as $reply) {
            $this->assertSame(['Yes', 'No'], array_column($service->parse($reply, true)['quick_replies'], 'label'));
            $this->assertSame([], $service->parse($reply, false)['quick_replies']);
        }
        foreach (['Which phone do you have?', 'How can we help?', 'Is your phone iOS or Android?', 'Have you tried restarting? Can you see the eSIM?', 'Is your email address correct?', 'Your device is supported or not supported depending on the model.', 'Hello.'] as $reply) {
            $this->assertSame([], $service->parse($reply, true)['quick_replies'], $reply);
        }
        $result = $service->parse('{"reply":"Does your phone support eSIM?","quick_replies":["Supported","Not supported","Not sure"]}', true);
        $this->assertSame(['Supported', 'Not supported', 'Not sure'], array_column($result['quick_replies'], 'label'));
    }

    public function test_dynamic_questions_preserve_arbitrary_relevant_and_localized_choices(): void
    {
        $cases = [
            ['Which device are you using?', ['iPhone', 'Samsung', 'Another device']],
            ['How did you try to install it?', ['QR code', 'Manual entry', 'In-app installation']],
            ['Where does the setup stop?', ['Before scanning', 'During installation', 'After activation']],
            ['What do you need help with?', ['Design a website', 'Build an app', 'Improve existing software']],
            ['আপনি কোন ডিভাইস ব্যবহার করছেন?', ['আইফোন', 'অ্যান্ড্রয়েড', 'অন্য ডিভাইস']],
        ];
        foreach ($cases as [$question, $choices]) {
            $result = (new ChatReplyOptions)->parse(json_encode(['reply' => $question, 'quick_replies' => $choices], JSON_UNESCAPED_UNICODE), true);
            $this->assertSame($question, $result['display_body']);
            $this->assertSame($choices, array_column($result['quick_replies'], 'label'));
            $this->assertSame(['qr_1', 'qr_2', 'qr_3'], array_column($result['quick_replies'], 'id'));
        }
    }
}
