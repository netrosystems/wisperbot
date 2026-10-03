<?php

namespace App\Modules\Inbox\Services;

/**
 * A Smart Bot reply written for the website chat (Markdown) made readable on
 * a channel that shows plain text.
 *
 * WhatsApp has its own light formatting (*bold*, _italic_, ~strike~, `code`,
 * "> " quotes), so Markdown emphasis becomes that. Messenger, Instagram,
 * Telegram (sent without a parse mode), eBay and email show text as written,
 * so the markers are removed. Links keep their address: "label (url)".
 * The website chat renders Markdown itself and is left alone.
 */
class ChannelTextFormatter
{
    private const CHANNELS = ['whatsapp', 'messenger', 'instagram', 'telegram', 'ebay', 'email'];

    public function format(string $text, string $channel): string
    {
        if (! in_array($channel, self::CHANNELS, true) || trim($text) === '') {
            return $text;
        }
        $whatsapp = $channel === 'whatsapp';

        // Links first, then keep every address out of reach of the emphasis rules.
        $text = (string) preg_replace_callback('~!?\[([^\]\n]*)\]\((https?://[^\s)]+)\)~u', function (array $m): string {
            $label = trim($m[1]);
            $bare = preg_replace('~^https?://(www\.)?|/$~i', '', $m[2]);

            return $label === '' || $label === $m[2] || $label === $bare || str_starts_with($m[0], '!') ? $m[2] : "{$label} ({$m[2]})";
        }, $text);
        $urls = [];
        $text = (string) preg_replace_callback('~https?://[^\s<>"]+~u', function (array $m) use (&$urls): string {
            $urls[] = $m[0];

            return "\u{E000}".(count($urls) - 1)."\u{E001}";
        }, $text);

        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // Fences, rules and table separators carry no words.
            if (preg_match('/^\s*(```.*|([-*_])\s*(\2\s*){2,}|\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?)\s*$/u', $line)) {
                continue;
            }
            if (preg_match('/^\s*\|(.+)\|\s*$/u', $line, $m)) {
                $line = implode(' – ', array_filter(array_map('trim', explode('|', $m[1])), fn ($cell) => $cell !== ''));
            }
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/u', $line, $m)) {
                $heading = trim(preg_replace('/\*\*|__/u', '', $m[1]) ?? $m[1]);
                $line = $whatsapp ? "**{$heading}**" : $heading; // bold, converted below
            }
            $line = (string) preg_replace('/^(\s*)[-*+]\s+/u', $channel === 'email' ? '$1- ' : '$1• ', $line);
            if (! $whatsapp) {
                $line = (string) preg_replace('/^\s*>\s?/u', '', $line);
            }
            $lines[] = $line;
        }
        $text = implode("\n", $lines);

        // Single-marker italics before bold, so converted bold is not read as italic.
        $text = (string) preg_replace('/(?<![*\w])\*(?![\s*])([^*\n]+?)(?<![\s*])\*(?![*\w])/u', $whatsapp ? '_$1_' : '$1', $text);
        $text = (string) preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*|__(?!\s)(.+?)(?<!\s)__/u', $whatsapp ? '*$1$2*' : '$1$2', $text);
        $text = (string) preg_replace('/~~(?!\s)(.+?)(?<!\s)~~/u', $whatsapp ? '~$1~' : '$1', $text);
        if (! $whatsapp) {
            $text = (string) preg_replace('/`([^`\n]+)`/u', '$1', $text);
        }

        $text = (string) preg_replace_callback("/\u{E000}(\d+)\u{E001}/u", fn (array $m): string => $urls[(int) $m[1]] ?? '', $text);

        return trim((string) preg_replace("/\n{3,}/u", "\n\n", $text));
    }
}
