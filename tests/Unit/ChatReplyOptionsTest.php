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
}
