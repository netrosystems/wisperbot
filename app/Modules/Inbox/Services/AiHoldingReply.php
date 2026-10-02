<?php

namespace App\Modules\Inbox\Services;

/**
 * What the customer receives when the Smart Bot could not produce a reply:
 * a short message in their script saying a person will answer, so the turn
 * is never silent. The caller then hands the conversation over.
 */
class AiHoldingReply
{
    public const HANDOFF_REASON = 'ai_unavailable';

    /** @return array{reply:string,display_body:string,resources:array<int,mixed>,quick_replies:array<int,mixed>,answer_origin:string,response_mode:string,citations:array<int,mixed>,product_facts:array<int,mixed>} */
    public function result(string $customerText): array
    {
        $reply = $this->text($customerText);

        return [
            'reply' => $reply,
            'display_body' => $reply,
            'resources' => [],
            'quick_replies' => [],
            'answer_origin' => 'fallback',
            'response_mode' => 'handoff',
            'citations' => [],
            'product_facts' => [],
        ];
    }

    public function text(string $customerText): string
    {
        return match (true) {
            (bool) preg_match('/\p{Bengali}/u', $customerText) => 'আপনার বার্তার জন্য ধন্যবাদ। আমাদের টিমের একজন সদস্য শিগগিরই এখানে উত্তর দেবেন।',
            (bool) preg_match('/\p{Arabic}/u', $customerText) => 'شكرًا لرسالتك. سيرد عليك أحد أعضاء فريقنا هنا قريبًا.',
            (bool) preg_match('/\p{Devanagari}/u', $customerText) => 'आपके संदेश के लिए धन्यवाद। हमारी टीम का एक सदस्य जल्द ही यहाँ जवाब देगा।',
            default => 'Thanks for your message. A member of our team will reply here shortly.',
        };
    }
}
