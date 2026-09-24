<?php

namespace App\Modules\Social\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Validates what a post will publish to X. A client may customise the X
 * version (`network_content.twitter`: its own text, and media chosen from the
 * post's media); then only that version must follow X's rules and the shared
 * content is free to carry links or more media for other networks.
 */
class XPostContent
{
    /** Request rules for the optional X version, shared by the web and API. */
    public const RULES = [
        'network_content' => ['nullable', 'array'],
        'network_content.twitter' => ['nullable', 'array'],
        'network_content.twitter.body' => ['required_with:network_content.twitter', 'nullable', 'string', 'max:5000'],
        'network_content.twitter.media_urls' => ['nullable', 'array', 'max:10'],
        'network_content.twitter.media_urls.*' => ['nullable', 'string', 'max:2048'],
    ];

    public function __construct(private readonly XContentRules $rules) {}

    /**
     * @param  Collection<int, string>  $networks  Networks of the selected accounts.
     * @param  array<string, mixed>|null  $networkContent  Validated `network_content` input.
     * @param  array<int, mixed>  $mediaUrls  The post's shared media.
     * @return array<string, array{body: string, media_urls: list<string>}>|null What to store.
     *
     * @throws ValidationException
     */
    public function validate(Collection $networks, ?string $body, array $mediaUrls, ?array $networkContent): ?array
    {
        if (! $networks->contains('twitter')) {
            return null;
        }

        $custom = $networkContent['twitter'] ?? null;
        if (! is_array($custom) || ! array_key_exists('body', $custom)) {
            $this->assertValid($body, $mediaUrls, 'body', 'media_urls');

            return null;
        }

        $shared = array_values(array_filter(array_map('strval', $mediaUrls)));
        $xMedia = array_values(array_unique(array_filter(array_map('strval', (array) ($custom['media_urls'] ?? [])))));
        if (array_diff($xMedia, $shared) !== []) {
            throw ValidationException::withMessages([
                'network_content.twitter.media_urls' => ['Choose X media from this post\'s media.'],
            ]);
        }

        $xBody = (string) ($custom['body'] ?? '');
        $this->assertValid($xBody, $xMedia, 'network_content.twitter.body', 'network_content.twitter.media_urls');

        return ['twitter' => ['body' => $xBody, 'media_urls' => $xMedia]];
    }

    /** @param  array<int, mixed>  $mediaUrls */
    private function assertValid(?string $body, array $mediaUrls, string $bodyKey, string $mediaKey): void
    {
        $errors = $this->rules->errors($body, $mediaUrls);
        if ($errors === []) {
            return;
        }

        $media = array_values(array_intersect($errors, [XContentRules::MEDIA_COMBINATION_ERROR, XContentRules::UNSUPPORTED_MEDIA_ERROR]));
        $text = array_values(array_diff($errors, $media));

        throw ValidationException::withMessages(array_filter([$bodyKey => $text, $mediaKey => $media]));
    }
}
