<?php

namespace App\Modules\Social\Services;

/**
 * What an X post may contain. Product decisions to limit X API credit use:
 * no links (2026-09-24: a link post costs about 13x a plain one), and at most
 * 3 images or 1 video/GIF (2026-09-24: X bills each uploaded media object like
 * a post). No media metadata (alt text) is sent. Content is rejected, never
 * altered, so a client always sees exactly what will be published.
 */
class XContentRules
{
    public const MAX_WEIGHTED_LENGTH = 280;

    public const MAX_IMAGES = 3;

    /** Byte limits per kind. Images and GIFs are X's limits; video is capped
     * to what the social queue can download and upload within its timeout. */
    public const MAX_BYTES = [
        'image' => 5 * 1024 * 1024,
        'gif' => 15 * 1024 * 1024,
        'video' => 50 * 1024 * 1024,
    ];

    /** MIME type => [kind, X media_category]. */
    public const MEDIA_TYPES = [
        'image/jpeg' => ['image', 'tweet_image'],
        'image/png' => ['image', 'tweet_image'],
        'image/webp' => ['image', 'tweet_image'],
        'image/gif' => ['gif', 'tweet_gif'],
        'video/mp4' => ['video', 'tweet_video'],
        'video/quicktime' => ['video', 'tweet_video'],
    ];

    public const MEDIA_COMBINATION_ERROR = 'X posts can have up to 3 images, or 1 video or GIF. Remove the extra media, or deselect X.';

    public const UNSUPPORTED_MEDIA_ERROR = 'X accepts JPG, PNG and WEBP images, GIFs, and MP4 or MOV videos.';

    /**
     * Any scheme (https://, ftp://), "www.", or a bare domain including
     * internationalised ones and shorteners such as bit.ly. Bare-domain
     * matching also catches e-mail addresses and tokens like "Node.js";
     * the message tells the client to add a space or remove the dot.
     */
    private const LINK_PATTERN = '~(?:[a-z][a-z0-9+.-]*://|www\.|(?<![\p{L}\p{N}_])[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?)*\.(?:[\p{L}]{2,63})(?![\p{L}\p{N}_]))~iu';

    /**
     * @param  array<int, mixed>  $mediaUrls
     * @return list<string> Empty when the content can be posted to X.
     */
    public function errors(?string $body, array $mediaUrls = []): array
    {
        $text = (string) $body;
        $errors = [];

        if (trim($text) === '') {
            $errors[] = 'X posts need text.';
        } elseif (! mb_check_encoding($text, 'UTF-8') || preg_match('/[\x{FFFE}\x{FEFF}\x{FFFF}]/u', $text)) {
            return ['X post text contains invalid characters.'];
        }

        if ($this->containsLink($text)) {
            $errors[] = 'X posts cannot contain links (including web addresses without https:// and e-mail addresses). Remove the link, or deselect X.';
        }

        if ($this->weightedLength($text) > self::MAX_WEIGHTED_LENGTH) {
            $errors[] = 'X posts are limited to 280 characters (emoji and many non-Latin characters count as 2).';
        }

        $kinds = array_map(fn ($url) => $this->kindFromUrl((string) $url), array_values(array_filter($mediaUrls)));
        if (($mediaError = $this->mediaError($kinds)) !== null) {
            $errors[] = $mediaError;
        }

        return $errors;
    }

    /**
     * @param  list<string|null>  $kinds  'image', 'gif' or 'video'; null when unsupported.
     */
    public function mediaError(array $kinds): ?string
    {
        if (in_array(null, $kinds, true)) {
            return self::UNSUPPORTED_MEDIA_ERROR;
        }

        $images = count(array_filter($kinds, fn ($kind) => $kind === 'image'));
        $single = count($kinds) - $images;

        if ($images > self::MAX_IMAGES || $single > 1 || ($single === 1 && $images > 0)) {
            return self::MEDIA_COMBINATION_ERROR;
        }

        return null;
    }

    /**
     * A best guess from the file extension, used before the file is fetched.
     * Unknown extensions count as images; the downloaded bytes decide later.
     */
    public function kindFromUrl(string $url): ?string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($extension) {
            'mp4', 'mov', 'm4v' => 'video',
            'gif' => 'gif',
            'bmp', 'svg', 'tif', 'tiff', 'heic', 'avi', 'mkv', 'webm', 'wmv', 'pdf' => null,
            default => 'image',
        };
    }

    public function kindFromMime(string $mime): ?string
    {
        return self::MEDIA_TYPES[strtolower($mime)][0] ?? null;
    }

    public function containsLink(string $text): bool
    {
        return preg_match(self::LINK_PATTERN, $text) === 1;
    }

    /**
     * X's weighted count: grapheme clusters after NFC; emoji count 2; code
     * points in the Latin-heavy ranges below count 1 and everything else
     * (CJK, most non-Latin scripts) counts 2.
     */
    public function weightedLength(string $text): int
    {
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        preg_match_all('/\X/u', $text, $clusters);
        $length = 0;

        foreach ($clusters[0] as $cluster) {
            if (preg_match('/\p{Emoji_Presentation}|\p{Extended_Pictographic}\x{FE0F}|[0-9#*]\x{FE0F}?\x{20E3}/u', $cluster)) {
                $length += 2;

                continue;
            }

            foreach (mb_str_split($cluster) as $character) {
                $code = mb_ord($character);
                $length += ($code <= 0x10FF || ($code >= 0x2000 && $code <= 0x200D)
                    || ($code >= 0x2010 && $code <= 0x201F) || ($code >= 0x2032 && $code <= 0x2037)) ? 1 : 2;
            }
        }

        return $length;
    }
}
