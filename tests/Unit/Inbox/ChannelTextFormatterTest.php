<?php

namespace Tests\Unit\Inbox;

use App\Modules\Inbox\Services\ChannelTextFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Smart Bot replies are written in Markdown for the website chat; each chat
 * channel gets them in the formatting it can show.
 */
class ChannelTextFormatterTest extends TestCase
{
    private const REPLY = "## Delivery\n\nWe deliver to **Sylhet** in *2 days*. See [the delivery guide](https://example.com/help_page__v2).\n\n- Fee: 120 taka\n- Cash on delivery\n\n| Area | Fee |\n|---|---|\n| Dhaka | 60 |";

    public function test_whatsapp_gets_its_own_bold_and_italics_and_links_keep_their_address(): void
    {
        $this->assertSame(
            "*Delivery*\n\nWe deliver to *Sylhet* in _2 days_. See the delivery guide (https://example.com/help_page__v2).\n\n• Fee: 120 taka\n• Cash on delivery\n\nArea – Fee\nDhaka – 60",
            (new ChannelTextFormatter)->format(self::REPLY, 'whatsapp'),
        );
    }

    public function test_messenger_instagram_and_telegram_get_plain_text(): void
    {
        foreach (['messenger', 'instagram', 'telegram'] as $channel) {
            $this->assertSame(
                "Delivery\n\nWe deliver to Sylhet in 2 days. See the delivery guide (https://example.com/help_page__v2).\n\n• Fee: 120 taka\n• Cash on delivery\n\nArea – Fee\nDhaka – 60",
                (new ChannelTextFormatter)->format(self::REPLY, $channel),
            );
        }
    }

    public function test_the_website_chat_keeps_markdown_and_plain_replies_are_unchanged(): void
    {
        $formatter = new ChannelTextFormatter;

        $this->assertSame(self::REPLY, $formatter->format(self::REPLY, 'webchat'));
        $plain = "Yes, we deliver to Sylhet.\n\n1. Track my order\n2. Talk to a person";
        $this->assertSame($plain, $formatter->format($plain, 'whatsapp'));
        $this->assertSame('Price: 5 * 2 = 10 taka, see https://example.com/a_b_c', $formatter->format('Price: 5 * 2 = 10 taka, see https://example.com/a_b_c', 'messenger'));
    }

    public function test_a_link_whose_label_is_its_address_is_written_once(): void
    {
        $this->assertSame('Visit https://example.com today.', (new ChannelTextFormatter)->format('Visit [example.com](https://example.com) today.', 'instagram'));
    }
}
