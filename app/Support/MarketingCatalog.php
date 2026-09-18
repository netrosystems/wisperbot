<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;

class MarketingCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function pages(): array
    {
        $pages = config('marketing.pages', []);
        if (! self::freeWhiteLabel()) {
            $pages['products/chatbots']['faq'][1]['a'] = 'WisperBot supports white-label website chat on eligible plans. Appearance controls are available in your workspace; check your plan for branding-removal access.';
        }

        if (config('social_comments.enabled')) {
            $pages['products/social-media']['benefits'][] = 'Supported comment management';
            $pages['products/social-media']['sections'][] = [
                'title' => 'Give public comments their own workspace.',
                'body' => 'Manage supported Facebook and Instagram comments separately from private conversations. Review AI suggestions or explicitly enable automatic replies when the account has the required access.',
                'points' => ['Permission-gated Meta comments', 'Public reply attribution', 'Account-level AI controls'],
            ];
        }

        foreach ($pages as $slug => &$page) {
            $page['slug'] = $slug;
            $page['href'] = '/'.$slug;
        }

        return $pages;
    }

    /** @return array<string, mixed> */
    public static function publicData(): array
    {
        $integrations = config('marketing.integrations', []);
        if (config('social_comments.enabled')) {
            foreach ($integrations as &$integration) {
                if (in_array($integration['key'], ['facebook', 'instagram'], true)) {
                    $integration['capabilities'][] = 'Comments';
                    $integration['note'] .= ' Comments require additional approved access.';
                }
            }
        }

        return [
            'pages' => array_values(array_map(fn (array $page) => Arr::only($page, ['slug', 'href', 'name', 'icon', 'tagline', 'description', 'demo']), self::pages())),
            'integrations' => $integrations,
            'commentsEnabled' => (bool) config('social_comments.enabled'),
            'freeWhiteLabel' => self::freeWhiteLabel(),
        ];
    }

    public static function freeWhiteLabel(): bool
    {
        try {
            return Plan::query()->where('enabled', true)->where('monthly_price_cents', 0)->where('white_label_enabled', true)->exists();
        } catch (QueryException) {
            // The public installer must not promise an entitlement before plans exist.
            return false;
        }
    }

    /** @param list<string> $hosts */
    public static function safeUrl(?string $url, array $hosts = []): ?string
    {
        if (! $url) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $url) || str_contains($url, '\\')) {
            return null;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $hosts === [] ? $url : null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $hosts === [] || in_array(strtolower($parts['host']), $hosts, true) ? $url : null;
    }
}
