<?php

namespace App\Modules\Social\Services;

use App\Modules\Social\Exceptions\XPublishException;

/**
 * Turns a post's media URL into a local file checked against X's rules, so
 * nothing is uploaded (and charged) that X would reject.
 */
class XMediaFetcher
{
    public function __construct(private readonly SocialMediaFiles $files) {}

    /**
     * @return array{path: string, mime: string, kind: string, category: string, temporary: bool}
     */
    public function fetch(string $url): array
    {
        try {
            $file = $this->files->fetch($url, max(XContentRules::MAX_BYTES));
        } catch (\RuntimeException $e) {
            throw new XPublishException($e->getMessage(), 'content');
        }

        [$kind, $category] = XContentRules::MEDIA_TYPES[$file['mime']] ?? [null, null];
        $error = match (true) {
            $kind === null => XContentRules::UNSUPPORTED_MEDIA_ERROR,
            $file['size'] > XContentRules::MAX_BYTES[$kind] => match ($kind) {
                'image' => 'X images must be 5 MB or smaller.',
                'gif' => 'X GIFs must be 15 MB or smaller.',
                default => 'X videos must be 50 MB or smaller.',
            },
            default => null,
        };
        if ($error !== null) {
            $this->files->release($file);
            throw new XPublishException($error, 'content');
        }

        return ['path' => $file['path'], 'mime' => $file['mime'], 'kind' => $kind, 'category' => $category, 'temporary' => $file['temporary']];
    }
}
