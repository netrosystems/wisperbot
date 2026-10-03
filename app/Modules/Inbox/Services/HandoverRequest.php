<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;

/**
 * Whether a customer message asks for a person.
 *
 * Two ways: the customer asks in their own words ("talk to a human",
 * "মানুষের সাথে কথা বলতে চাই", "hablar con una persona"), or the bot's last
 * reply offered a person and the customer said yes. Both hand the
 * conversation to the team before any Smart Bot answer is written.
 *
 * The phrases are language patterns, not any business's wording.
 */
class HandoverRequest
{
    public const ASKED = 'user_request';

    public const ACCEPTED_OFFER = 'accepted_offer';

    /** Phrases that ask for a person on their own (normalised, whole words). */
    private const PHRASES = [
        'talk to human', 'talk to agent', 'speak to agent', 'speak to human', 'human please', 'real person', 'live agent',
        'live support', 'live person', 'human agent', 'need a human', 'want a human', 'connect me to', 'connect me with', 'transfer me',
        'customer service agent', 'manusher sathe kotha', 'manuser sathe kotha', 'agent er sathe kotha',
        'insaan se baat', 'insan se baat', 'agent se baat',
    ];

    /** Patterns for "talk to a person" across the languages WisperBot serves. */
    private const PATTERNS = [
        '/\b(?:talk|speak|chat)\s+(?:to|with)\s+(?:a\s+|an\s+|the\s+|your\s+|some\s+)?(?:human|person|people|agent|representative|rep|someone|somebody|staff|team member|real person|operator|support team)\b/u',
        '/\b(?:hablar|habla)\s+con\s+(?:un|una|el|la|alguien)\b(?:\s+(?:persona|humano|agente|asesor|representante))?/u',
        '/\bparler\s+(?:a|à|avec)\s+(?:un|une|quelqu)\S*(?:\s+(?:humain|personne|agent|conseiller|conseillère))?/u',
        '/\bfalar\s+com\s+(?:um|uma|algu[eé]m)\b/u',
        '/\bmit\s+einem\s+(?:menschen|mitarbeiter|berater)\b/u',
        '/\bbicara\s+dengan\s+(?:manusia|agen|cs|admin|orang)\b/u',
        '/(?:মানুষ|প্রতিনিধি|এজেন্ট|কর্মকর্তা|কাস্টমার কেয়ার|কাস্টমার সার্ভিস|অ্যাডমিন)\S*\s*(?:এর\s*)?(?:সাথে|সঙ্গে)\s*(?:কথা|যোগাযোগ)/u',
        '/(?:أريد|اريد|أتحدث|اتحدث|التحدث|أكلم|اكلم|كلمني|حولني|تحويلي)[^.؟?!]{0,30}(?:موظف|شخص|إنسان|انسان|ممثل|وكيل|خدمة العملاء)/u',
        '/(?:इंसान|व्यक्ति|एजेंट|प्रतिनिधि|कस्टमर केयर)[^.?!]{0,20}(?:से\s*बात)/u',
    ];

    /** A short yes, in the scripts customers write in. */
    private const YES = [
        'yes', 'yeah', 'yep', 'yup', 'sure', 'ok', 'okay', 'please', 'ha', 'haa', 'han', 'haan', 'ji', 'jee', 'thik ache',
        'হ্যাঁ', 'হা', 'হাঁ', 'জি', 'জ্বি', 'ঠিক আছে', 'نعم', 'ايوه', 'أيوه', 'اوكي', 'हाँ', 'हां', 'जी', 'sí', 'si', 'oui', 'ja', 'sim', 'iya', 'ya',
    ];

    /** The bot's own offer of a person, in its last reply. */
    private const OFFER_PATTERNS = [
        '/\b(?:connect|transfer|put you through|hand (?:you )?over|pass you|talk|speak|chat|reach|get you)\b[^?]{0,60}\b(?:person|human|team|agent|someone|representative|staff|colleague|specialist|member of)\b[^?]*\?/iu',
        '/\bhuman help\b[^?]*\?/iu',
        '/(?:টিম|প্রতিনিধি|এজেন্ট|মানুষ|কর্মী|সহকর্মী)[^?？]{0,60}(?:সাথে|সঙ্গে|যুক্ত|কথা)[^?？]{0,40}[?？]/u',
        '/\b(?:team|agent|manush|protinidhi)\b[^?]{0,40}\b(?:sathe|songe|connect)\b[^?]*\?/iu',
        '/(?:موظف|فريق|ممثل|شخص)[^؟?]{0,60}[؟?]/u',
        '/(?:टीम|प्रतिनिधि|एजेंट|व्यक्ति)[^?]{0,60}\?/u',
    ];

    /** Why this message hands the conversation over, or null when it does not. */
    public function reason(Message $inbound, Conversation $conversation): ?string
    {
        $text = (string) $inbound->body;
        if ($this->asks($text)) {
            return self::ASKED;
        }
        if (! $this->saysYes($text)) {
            return null;
        }
        $previous = $conversation->messages()->where('direction', 'out')
            ->when($inbound->id, fn ($query) => $query->where('id', '<', $inbound->id))
            ->latest('id')->first();

        return $previous && $previous->sent_by === 'bot' && $this->offersPerson((string) $previous->body) ? self::ACCEPTED_OFFER : null;
    }

    public function asks(string $text): bool
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return false;
        }
        foreach (self::PHRASES as $phrase) {
            if (str_contains(" {$normalized} ", " {$phrase} ")) {
                return true;
            }
        }
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    public function offersPerson(string $botText): bool
    {
        foreach (self::OFFER_PATTERNS as $pattern) {
            if (preg_match($pattern, $botText)) {
                return true;
            }
        }

        return false;
    }

    /** What the customer is told on a channel, in their script. */
    public function acknowledgement(string $customerText): string
    {
        return match (true) {
            (bool) preg_match('/\p{Bengali}/u', $customerText) => 'অবশ্যই। আমাদের টিমের একজন সদস্য শিগগিরই এখানে উত্তর দেবেন।',
            (bool) preg_match('/\p{Arabic}/u', $customerText) => 'بالتأكيد. سيرد عليك أحد أعضاء فريقنا هنا قريبًا.',
            (bool) preg_match('/\p{Devanagari}/u', $customerText) => 'ज़रूर। हमारी टीम का एक सदस्य जल्द ही यहाँ जवाब देगा।',
            default => 'Of course. A member of our team will reply here shortly.',
        };
    }

    /** "yes", "yes please", "ok connect me": a yes word first, and short. */
    private function saysYes(string $text): bool
    {
        $normalized = $this->normalize($text);
        if ($normalized === '' || count(explode(' ', $normalized)) > 5) {
            return false;
        }
        foreach (self::YES as $yes) {
            if ($normalized === $yes || str_starts_with($normalized, "{$yes} ")) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[\p{P}\p{S}\p{Z}]+/u', ' ', $text) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
