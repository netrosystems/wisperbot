<?php

namespace App\Modules\Social\Services;

use Illuminate\Support\Collection;

/**
 * What media each network's publishing API accepts, checked when a post is
 * saved so a client never schedules something that can only fail. The kind
 * comes from the file extension; drivers check the real file where the
 * provider needs the bytes. X has its own rules in XContentRules.
 */
class SocialMediaRules
{
    public const MAX_FACEBOOK_IMAGES = 10;

    public const MAX_INSTAGRAM_ITEMS = 10;

    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v'];

    /**
     * @param  Collection<int, string>  $networks  Networks of the selected accounts.
     * @param  array<int, mixed>  $mediaUrls
     * @return list<string> Empty when every selected network accepts the media.
     */
    public function errors(Collection $networks, array $mediaUrls): array
    {
        $urls = array_values(array_filter(array_map('strval', $mediaUrls)));
        $videos = count(array_filter($urls, fn (string $url) => $this->isVideo($url)));
        $images = count($urls) - $videos;
        $errors = [];

        if ($networks->contains('facebook') && ($videos > 1 || ($videos === 1 && $images > 0) || $images > self::MAX_FACEBOOK_IMAGES)) {
            $errors[] = 'Facebook posts can have up to 10 images, or 1 video. Remove the extra media, or deselect Facebook.';
        }

        if ($networks->contains('instagram')) {
            if ($urls === []) {
                $errors[] = 'Instagram publishing requires at least one publicly reachable image or video.';
            } elseif (count($urls) > self::MAX_INSTAGRAM_ITEMS) {
                $errors[] = 'Instagram posts can have up to 10 images or videos. Remove the extra media, or deselect Instagram.';
            }
            foreach ($urls as $url) {
                if (! $this->isVideo($url) && ! in_array($this->extension($url), ['jpg', 'jpeg', ''], true)) {
                    $errors[] = 'Instagram only accepts JPG images. Use a JPG version of each image, or deselect Instagram.';
                    break;
                }
            }
        }

        if ($networks->contains('linkedin') && count($urls) > 1) {
            $errors[] = 'LinkedIn posts can have 1 image or 1 video. Remove the extra media, or deselect LinkedIn.';
        }

        if ($networks->intersect(['youtube', 'tiktok'])->isNotEmpty() && $videos === 0) {
            $errors[] = 'YouTube and TikTok publishing require a publicly reachable video (MP4 or MOV).';
        }

        return $errors;
    }

    public function isVideo(string $url): bool
    {
        return in_array($this->extension($url), self::VIDEO_EXTENSIONS, true);
    }

    /**
     * @param  array<int, mixed>  $mediaUrls
     */
    public function firstVideo(array $mediaUrls): ?string
    {
        foreach ($mediaUrls as $url) {
            if (is_string($url) && $url !== '' && $this->isVideo($url)) {
                return $url;
            }
        }

        return null;
    }

    private function extension(string $url): string
    {
        return strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    }
}
