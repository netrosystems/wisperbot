<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;

class BusinessAwareTurnRouter
{
    /**
     * Handle only complete, low-risk social turns. A greeting combined with a
     * business question deliberately returns null and continues through RAG.
     *
     * @return array{reply:string,display_body:string,quick_replies:array<int,array{id:string,label:string}>,resources:array<int,array<string,mixed>>,tokens_used:int,answer_origin:string,response_mode:string,citations:array<int,array{title:string,url:string}>,intent:string}|null
     */
    public function conversationalResult(string $message, ?AiKnowledgeBase $knowledgeBase, ?string $tone = null): ?array
    {
        $normalized = $this->normalizeSocialTurn($message);
        if ($normalized === '') {
            return null;
        }

        $intent = $this->socialIntent($normalized);
        if ($intent === null) {
            return null;
        }

        $language = $this->language($message);
        $brand = $this->safeBrand($knowledgeBase?->brand);
        $reply = $this->socialReply($intent, $language, $brand, $tone);

        return [
            'reply' => $reply,
            'display_body' => $reply,
            'quick_replies' => [],
            'resources' => [],
            'tokens_used' => 0,
            'answer_origin' => 'conversation',
            'response_mode' => 'answer',
            'citations' => [],
            'intent' => $intent,
        ];
    }

    /**
     * Decide what may happen after strict KB retrieval produced no passage.
     * Classification is deterministic and embedding-assisted, so it cannot
     * create a second charge for the same customer action.
     *
     * @return array{mode:string,intent:string,scope:string,profile_complete:bool}
     */
    public function routeMissingContext(
        AiChatbot $bot,
        ?AiKnowledgeBase $knowledgeBase,
        string $message,
        float $profileSimilarity,
        float $bestRetrievalScore,
    ): array {
        $scope = in_array($bot->answer_scope, ['business_only', 'verified_only', 'general'], true)
            ? $bot->answer_scope
            : (($bot->unsupported_answer_action ?? null) === 'general' ? 'general' : 'business_only');
        $profileComplete = $this->hasMeaningfulProfile($knowledgeBase);

        if ($this->requiresHumanHandling($message)) {
            return ['scope' => $scope, 'profile_complete' => $profileComplete, 'mode' => 'fallback', 'intent' => 'sensitive_or_unsafe'];
        }

        if ($scope === 'general') {
            return ['scope' => $scope, 'profile_complete' => $profileComplete, 'mode' => 'guidance', 'intent' => 'general_assistant'];
        }

        if (! $profileComplete || $scope === 'verified_only') {
            $research = $profileComplete && $scope === 'verified_only' && $bot->trusted_research_enabled;

            return ['scope' => $scope, 'profile_complete' => $profileComplete] + [
                'mode' => $research ? 'research' : 'fallback',
                'intent' => $research ? 'business_research' : 'verified_evidence_required',
            ];
        }

        if ($this->clearlyOutsideProfile($message, $knowledgeBase)) {
            return ['scope' => $scope, 'profile_complete' => $profileComplete, 'mode' => 'fallback', 'intent' => 'unrelated'];
        }

        $related = $profileSimilarity >= (float) config('chatbot.business_domain_similarity_threshold', 0.42)
            || $bestRetrievalScore >= (float) config('chatbot.business_retrieval_hint_threshold', 0.34);
        if (! $related) {
            return ['scope' => $scope, 'profile_complete' => $profileComplete, 'mode' => 'fallback', 'intent' => 'unrelated'];
        }

        if ($this->requiresCompanyEvidence($message) || $this->mayNeedFreshEvidence($message)) {
            return ['scope' => $scope, 'profile_complete' => $profileComplete] + [
                'mode' => $bot->trusted_research_enabled ? 'research' : 'fallback',
                'intent' => 'business_fact',
            ];
        }

        return ['scope' => $scope, 'profile_complete' => $profileComplete, 'mode' => 'guidance', 'intent' => 'business_guidance'];
    }

    public function hasMeaningfulProfile(?AiKnowledgeBase $knowledgeBase): bool
    {
        if (! $knowledgeBase) {
            return false;
        }

        $brand = trim((string) $knowledgeBase->brand);
        $purpose = trim((string) $knowledgeBase->purpose);
        $audience = trim((string) $knowledgeBase->audience);

        return mb_strlen($brand) >= 2
            && mb_strlen($purpose) >= 12
            && count($this->words($purpose)) >= 2
            && mb_strlen($audience) >= 3;
    }

    public function profileText(AiKnowledgeBase $knowledgeBase): string
    {
        return implode("\n", [
            'Business: '.trim((string) $knowledgeBase->brand),
            'Purpose: '.trim((string) $knowledgeBase->purpose),
            'Audience: '.trim((string) $knowledgeBase->audience),
            'Knowledge Base: '.trim((string) $knowledgeBase->name),
        ]);
    }

    /**
     * Fresh purchasing, price, availability and status questions may be
     * checked against the client's explicitly approved website sources.
     */
    public function shouldResearchQuestion(string $message): bool
    {
        return $this->mayNeedFreshEvidence($message)
            || (bool) preg_match('/\b(?:buy|purchase|order|book|subscribe|sign up)\b/iu', $message);
    }

    private function normalizeSocialTurn(string $message): string
    {
        $message = mb_strtolower(trim($message));
        $message = preg_replace('/[\p{P}\p{S}\p{Z}]+/u', ' ', $message) ?? $message;

        return trim((string) preg_replace('/\s+/u', ' ', $message));
    }

    private function socialIntent(string $normalized): ?string
    {
        $groups = [
            'greeting' => [
                'hi', 'hello', 'hey', 'hi there', 'hello there', 'good morning', 'good afternoon', 'good evening',
                'হাই', 'হ্যালো', 'সালাম', 'আসসালামু আলাইকুম', 'مرحبا', 'السلام عليكم', 'hola', 'bonjour', 'salut',
                'hallo', 'namaste', 'नमस्ते', 'halo', 'ciao', 'こんにちは', '안녕하세요', 'olá', 'ola', 'привет', 'merhaba', '你好',
            ],
            'thanks' => [
                'thanks', 'thank you', 'thank you very much', 'thx', 'ধন্যবাদ', 'شكرا', 'gracias', 'merci', 'danke',
                'धन्यवाद', 'terima kasih', 'grazie', 'ありがとう', '감사합니다', 'obrigado', 'obrigada', 'спасибо', 'teşekkürler', '谢谢',
            ],
            'acknowledgement' => [
                'ok', 'okay', 'got it', 'understood', 'sounds good', 'all right', 'ঠিক আছে', 'বুঝেছি', 'حسنا', 'vale',
                'd accord', 'verstanden', 'ठीक है', 'baik', 'capito', 'わかりました', '알겠습니다', 'entendi', 'понятно', 'tamam', '明白了',
            ],
            'goodbye' => [
                'bye', 'goodbye', 'see you', 'take care', 'বিদায়', 'আল্লাহ হাফেজ', 'مع السلامة', 'adiós', 'au revoir',
                'tschüss', 'अलविदा', 'sampai jumpa', 'arrivederci', 'さようなら', '안녕히 가세요', 'tchau', 'до свидания', 'güle güle', '再见',
            ],
        ];

        foreach ($groups as $intent => $phrases) {
            if (in_array($normalized, $phrases, true)) {
                return $intent;
            }
        }

        return null;
    }

    private function socialReply(string $intent, string $language, ?string $brand, ?string $tone): string
    {
        $friendly = $tone !== 'formal';
        $name = $brand ? ' '.$brand : '';
        $english = match ($intent) {
            'greeting' => $friendly ? "Hello! How can{$name} help you today?" : "Hello. How may{$name} assist you today?",
            'thanks' => 'You’re welcome! Is there anything else I can help with?',
            'acknowledgement' => 'Got it. Let me know what you would like help with next.',
            'goodbye' => 'Goodbye! Feel free to return whenever you need help.',
            default => 'How can I help?',
        };

        $localized = [
            'bn' => [
                'greeting' => 'হ্যালো! আজ আপনাকে কীভাবে সাহায্য করতে পারি?',
                'thanks' => 'আপনাকে স্বাগতম! আর কিছুতে সাহায্য করতে পারি?',
                'acknowledgement' => 'ঠিক আছে। এরপর কী বিষয়ে সাহায্য চান বলুন।',
                'goodbye' => 'বিদায়! প্রয়োজন হলে আবার যোগাযোগ করুন।',
            ],
            'ar' => [
                'greeting' => 'مرحبًا! كيف يمكنني مساعدتك اليوم؟',
                'thanks' => 'على الرحب والسعة! هل يمكنني مساعدتك في شيء آخر؟',
                'acknowledgement' => 'حسنًا. أخبرني بما تحتاج إلى مساعدة فيه.',
                'goodbye' => 'إلى اللقاء! عد متى احتجت إلى المساعدة.',
            ],
            'es' => ['greeting' => '¡Hola! ¿Cómo puedo ayudarte hoy?', 'thanks' => '¡De nada! ¿Puedo ayudarte con algo más?', 'acknowledgement' => 'Entendido. Dime en qué más puedo ayudarte.', 'goodbye' => '¡Adiós! Vuelve cuando necesites ayuda.'],
            'fr' => ['greeting' => 'Bonjour ! Comment puis-je vous aider ?', 'thanks' => 'Avec plaisir ! Puis-je vous aider autrement ?', 'acknowledgement' => 'Compris. Dites-moi ce dont vous avez besoin.', 'goodbye' => 'Au revoir ! Revenez si vous avez besoin d’aide.'],
            'de' => ['greeting' => 'Hallo! Wie kann ich Ihnen heute helfen?', 'thanks' => 'Gern geschehen! Kann ich noch etwas tun?', 'acknowledgement' => 'Verstanden. Wobei kann ich als Nächstes helfen?', 'goodbye' => 'Auf Wiedersehen! Melden Sie sich gern wieder.'],
            'hi' => ['greeting' => 'नमस्ते! आज मैं आपकी कैसे मदद कर सकता हूँ?', 'thanks' => 'आपका स्वागत है! क्या मैं किसी और चीज़ में मदद कर सकता हूँ?', 'acknowledgement' => 'समझ गया। बताइए आगे किसमें मदद चाहिए।', 'goodbye' => 'अलविदा! ज़रूरत होने पर फिर आइए।'],
            'id' => ['greeting' => 'Halo! Ada yang bisa saya bantu hari ini?', 'thanks' => 'Sama-sama! Ada lagi yang bisa saya bantu?', 'acknowledgement' => 'Baik. Beri tahu saya bantuan berikutnya.', 'goodbye' => 'Sampai jumpa! Kembali kapan saja jika perlu bantuan.'],
            'it' => ['greeting' => 'Ciao! Come posso aiutarti oggi?', 'thanks' => 'Prego! Posso aiutarti con altro?', 'acknowledgement' => 'Capito. Dimmi come posso aiutarti ancora.', 'goodbye' => 'Arrivederci! Torna quando hai bisogno.'],
            'ja' => ['greeting' => 'こんにちは！今日はどのようなお手伝いができますか？', 'thanks' => 'どういたしまして。ほかにお手伝いできることはありますか？', 'acknowledgement' => '承知しました。次に必要なことを教えてください。', 'goodbye' => 'さようなら。いつでもまたご相談ください。'],
            'ko' => ['greeting' => '안녕하세요! 오늘 무엇을 도와드릴까요?', 'thanks' => '천만에요! 더 도와드릴 일이 있나요?', 'acknowledgement' => '알겠습니다. 다음으로 필요한 도움을 알려주세요.', 'goodbye' => '안녕히 가세요! 도움이 필요하면 다시 찾아주세요.'],
            'pt' => ['greeting' => 'Olá! Como posso ajudar hoje?', 'thanks' => 'De nada! Posso ajudar em mais alguma coisa?', 'acknowledgement' => 'Entendido. Diga como posso ajudar a seguir.', 'goodbye' => 'Até logo! Volte quando precisar.'],
            'ru' => ['greeting' => 'Здравствуйте! Чем я могу помочь сегодня?', 'thanks' => 'Пожалуйста! Могу я помочь ещё чем-нибудь?', 'acknowledgement' => 'Понятно. Скажите, с чем помочь дальше.', 'goodbye' => 'До свидания! Возвращайтесь, если понадобится помощь.'],
            'tr' => ['greeting' => 'Merhaba! Bugün nasıl yardımcı olabilirim?', 'thanks' => 'Rica ederim! Başka nasıl yardımcı olabilirim?', 'acknowledgement' => 'Anladım. Sırada ne konuda yardım istediğinizi söyleyin.', 'goodbye' => 'Hoşça kalın! Yardım gerektiğinde tekrar gelin.'],
            'zh' => ['greeting' => '您好！今天需要什么帮助？', 'thanks' => '不客气！还有什么可以帮您？', 'acknowledgement' => '明白了。请告诉我接下来需要什么帮助。', 'goodbye' => '再见！需要帮助时欢迎回来。'],
        ];

        return $localized[$language][$intent] ?? $english;
    }

    private function language(string $message): string
    {
        return match (true) {
            (bool) preg_match('/[\x{0980}-\x{09FF}]/u', $message) => 'bn',
            (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $message) => 'ar',
            (bool) preg_match('/[\x{0900}-\x{097F}]/u', $message) => 'hi',
            (bool) preg_match('/[\x{3040}-\x{30FF}]/u', $message) => 'ja',
            (bool) preg_match('/[\x{AC00}-\x{D7AF}]/u', $message) => 'ko',
            (bool) preg_match('/[\x{4E00}-\x{9FFF}]/u', $message) => 'zh',
            (bool) preg_match('/[\x{0400}-\x{04FF}]/u', $message) => 'ru',
            (bool) preg_match('/\b(?:hola|gracias|adiós|vale)\b/iu', $message) => 'es',
            (bool) preg_match('/\b(?:bonjour|merci|salut|au revoir)\b/iu', $message) => 'fr',
            (bool) preg_match('/\b(?:hallo|danke|tschüss|verstanden)\b/iu', $message) => 'de',
            (bool) preg_match('/\b(?:halo|terima kasih|sampai jumpa|baik)\b/iu', $message) => 'id',
            (bool) preg_match('/\b(?:ciao|grazie|arrivederci|capito)\b/iu', $message) => 'it',
            (bool) preg_match('/\b(?:olá|ola|obrigad[oa]|tchau|entendi)\b/iu', $message) => 'pt',
            (bool) preg_match('/\b(?:merhaba|teşekkürler|tamam|güle güle)\b/iu', $message) => 'tr',
            default => 'en',
        };
    }

    private function safeBrand(?string $brand): ?string
    {
        $brand = trim((string) $brand);

        return $brand !== '' && mb_strlen($brand) <= 80 && ! preg_match('/[\r\n<>]/u', $brand) ? $brand : null;
    }

    private function requiresHumanHandling(string $message): bool
    {
        return (bool) preg_match('/\b(?:password|passcode|api.?key|credit.?card|bank account|suicide|self.?harm|medical emergency|diagnose|dosage|treatment for me|lawsuit|legal advice|should i invest|investment advice|complaint|scam|fraud|chargeback|ignore.{0,30}instructions|system prompt|developer message)\b/iu', $message);
    }

    private function clearlyOutsideProfile(string $message, AiKnowledgeBase $knowledgeBase): bool
    {
        $profile = mb_strtolower($this->profileText($knowledgeBase));
        $categories = [
            ['question' => '/\b(?:president|prime minister|election|politics|political party|congress|parliament)\b/iu', 'profile' => '/\b(?:politic|government|election|public policy|civic)\b/iu'],
            ['question' => '/\b(?:celebrity|actor|actress|singer|movie star)\b/iu', 'profile' => '/\b(?:entertainment|media|film|music|celebrity)\b/iu'],
            ['question' => '/\b(?:sports score|football score|cricket score|who won the match)\b/iu', 'profile' => '/\b(?:sport|football|cricket|athletic)\b/iu'],
            ['question' => '/\b(?:trivia|quiz question|capital of|who invented|historical fact)\b/iu', 'profile' => '/\b(?:education|school|learning|quiz|history|travel|geography)\b/iu'],
        ];
        foreach ($categories as $category) {
            if (preg_match($category['question'], $message) && ! preg_match($category['profile'], $profile)) {
                return true;
            }
        }

        return false;
    }

    private function requiresCompanyEvidence(string $message): bool
    {
        return (bool) preg_match('/\b(?:your|you offer|you provide|price|pricing|cost|fee|policy|refund|return|warranty|guarantee|available|availability|stock|plan|subscription|delivery|shipping|opening hours|address|location|product specification|compatible)\b/iu', $message);
    }

    private function mayNeedFreshEvidence(string $message): bool
    {
        return (bool) preg_match('/\b(?:today|now|current|currently|latest|recent|this week|this month|available|availability|stock|price|pricing|opening hours|status)\b/iu', $message);
    }

    /** @return array<int,string> */
    private function words(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($value), $matches);

        return array_values(array_unique($matches[0]));
    }
}
