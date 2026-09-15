<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\Media\AttachmentService;
use App\Services\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class MessageMediaResolver
{
    public function __construct(
        private readonly StorageManager $storageManager,
        private readonly AttachmentService $attachmentService,
    ) {}

    public function response(Message $message, Request $request): Response
    {
        $payload = $message->payload ?? [];
        $type = (string) ($message->type ?? 'image');
        $cached = $this->cachedPath($message, $payload);
        $disk = $this->storageManager->disk();

        if ($cached && $disk->exists($cached)) {
            if ($this->isHeicPath($cached)) {
                return $this->storeAndRedirect(
                    $message,
                    array_merge($payload, ['preview_url' => null]),
                    (string) $disk->get($cached),
                    $payload['mime_type'] ?? $payload[$type]['mime_type'] ?? $this->mimeTypeFromPath($cached),
                    $request,
                );
            }

            return $this->streamCachedMedia(
                $cached,
                $payload['mime_type'] ?? $payload[$type]['mime_type'] ?? null,
            );
        }

        if (in_array($message->channel, ['messenger', 'instagram'], true)) {
            return $this->metaResponse($message, $payload, $type, $request);
        }

        return $this->whatsappResponse($message, $payload, $type, $request);
    }

    public function mediaUrl(Message $message, Request $request, ?string $routeName = null): ?string
    {
        $payload = $message->payload ?? [];
        if (! $this->hasResolvableMedia($message, $payload)) {
            return null;
        }

        if ($this->routeShouldOwnPreview($routeName)) {
            return $this->routedMediaUrl($message, $routeName);
        }

        if (! empty($payload['preview_url'])) {
            return $this->browserSafePublicUrl((string) $payload['preview_url'], $request);
        }

        if ($routeName && $message->relationLoaded('conversation') && $message->conversation) {
            return $this->routeMediaUrl($routeName, $message);
        }

        return null;
    }

    public function augmentPayload(Message $message, Request $request, ?string $routeName = null): ?array
    {
        $payload = $message->payload;
        if (! $payload) {
            return $payload;
        }

        $mediaUrl = $this->mediaUrl($message, $request, $routeName);
        if ($mediaUrl) {
            $payload = $this->applyMediaUrlToPayload($payload, (string) $message->type, $mediaUrl, $routeName);
        }

        if (! empty($payload['preview_url'])) {
            $payload['preview_url'] = $this->browserSafePublicUrl((string) $payload['preview_url'], $request);
        }

        return $payload;
    }

    public function augmentPayloadForRoute(Message $message, ?string $routeName = null): ?array
    {
        $payload = $message->payload;
        if (! $payload) {
            return $payload;
        }

        $mediaUrl = $this->routedMediaUrl($message, $routeName);
        if ($mediaUrl) {
            $payload = $this->applyMediaUrlToPayload($payload, (string) $message->type, $mediaUrl, $routeName);
        }

        return $payload;
    }

    public function displayBody(Message $message): ?string
    {
        $body = (string) ($message->body ?? '');
        if ($body === '' || ! $this->isGenericMediaBody((string) $message->type, $body)) {
            return $message->body;
        }

        return '';
    }

    private function whatsappResponse(Message $message, array $payload, string $type, Request $request): Response
    {
        $mediaId = $payload[$type]['id'] ?? $payload['media_id'] ?? null;
        abort_if(! $mediaId, 404, 'No media available.');

        $workspaceId = $request->user()?->current_workspace_id
            ?? $request->user()?->workspace_id
            ?? $message->conversation?->workspace_id;
        abort_if(! $workspaceId, 503, 'WhatsApp account not configured.');

        $client = CloudApiClient::forWorkspace((int) $workspaceId);
        abort_if(! $client, 503, 'WhatsApp account not configured.');

        try {
            ['url' => $downloadUrl, 'mime_type' => $mimeType] = $client->getMediaUrl((string) $mediaId);
            $bytes = $client->downloadMedia($downloadUrl);

            return $this->storeAndRedirect($message, $payload, $bytes, $mimeType, $request);
        } catch (\Throwable $e) {
            abort(502, 'Could not fetch media: '.$e->getMessage());
        }
    }

    private function metaResponse(Message $message, array $payload, string $type, Request $request): Response
    {
        $remoteUrl = $this->metaRemoteUrl($payload, $type);
        abort_if(! $remoteUrl, 404, 'No media available.');
        abort_unless(str_starts_with(strtolower($remoteUrl), 'https://'), 422, 'Unsupported media URL.');

        try {
            $response = Http::timeout(20)->get($remoteUrl);
            abort_unless($response->successful(), 502, 'Could not fetch media.');

            $mimeType = (string) ($response->header('Content-Type') ?: ($payload['mime_type'] ?? 'application/octet-stream'));

            return $this->storeAndRedirect($message, $payload, $response->body(), $mimeType, $request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            abort(502, 'Could not fetch media: '.$e->getMessage());
        }
    }

    private function storeAndRedirect(Message $message, array $payload, string $bytes, string $mimeType, Request $request): Response
    {
        [$bytes, $mimeType, $payload] = $this->convertInboundHeicIfPossible($bytes, $mimeType, $payload, (string) $message->type);
        $extension = $this->extensionFromMime($mimeType);
        $filename = $this->storageManager->prefixedPath("message-media/{$message->id}.{$extension}");
        $disk = $this->storageManager->disk();
        $disk->put($filename, $bytes);
        $previewUrl = $this->browserSafePublicUrl($disk->url($filename), $request);

        $message->update(['payload' => array_merge($payload, [
            'preview_url' => $previewUrl,
            'mime_type' => $mimeType,
        ])]);

        return $this->streamCachedMedia($filename, $mimeType);
    }

    /**
     * Browser/mobile clients cannot reliably render HEIC/HEIF. Provider media is
     * cached through this resolver, so convert inbound Apple photos to JPEG here.
     *
     * @return array{0:string, 1:string, 2:array<string, mixed>}
     */
    private function convertInboundHeicIfPossible(string $bytes, string $mimeType, array $payload, string $type): array
    {
        if (! $this->isHeicMedia($mimeType, $payload, $type, $bytes)) {
            return [$bytes, $mimeType, $payload];
        }

        $tempBase = tempnam(sys_get_temp_dir(), 'inbound_heic_');
        if (! $tempBase) {
            return [$bytes, $mimeType, $payload];
        }
        $sourcePath = $tempBase.'.heic';
        @rename($tempBase, $sourcePath);

        file_put_contents($sourcePath, $bytes);

        try {
            $convertedPath = $this->attachmentService->attemptHeicConversion($sourcePath);
            if (! $convertedPath || ! file_exists($convertedPath)) {
                return [$bytes, $mimeType, $payload];
            }

            $convertedBytes = (string) file_get_contents($convertedPath);
            @unlink($convertedPath);

            return [$convertedBytes, 'image/jpeg', $this->withJpegMetadata($payload, $type)];
        } finally {
            @unlink($sourcePath);
        }
    }

    private function isHeicMedia(string $mimeType, array $payload, string $type, string $bytes): bool
    {
        $mime = strtolower(trim(explode(';', $mimeType)[0]));
        if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)) {
            return true;
        }

        foreach ([
            $payload['filename'] ?? null,
            $payload[$type]['filename'] ?? null,
            $payload[$type]['url'] ?? null,
            $payload['_meta_attachment']['url'] ?? null,
        ] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $extension = strtolower(pathinfo((string) parse_url($candidate, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (in_array($extension, ['heic', 'heif', 'heics', 'heifs'], true)) {
                return true;
            }
        }

        return strlen($bytes) >= 12
            && substr($bytes, 4, 4) === 'ftyp'
            && in_array(strtolower(substr($bytes, 8, 4)), ['heic', 'heix', 'hevc', 'heim', 'heis', 'mif1', 'msf1'], true);
    }

    private function withJpegMetadata(array $payload, string $type): array
    {
        $payload['mime_type'] = 'image/jpeg';
        $payload['is_converted_heic'] = true;
        $payload['original_mime_type'] = $payload['original_mime_type'] ?? 'image/heic';

        if (isset($payload[$type]) && is_array($payload[$type])) {
            $payload[$type]['mime_type'] = 'image/jpeg';
            $payload[$type]['is_converted_heic'] = true;
            if (isset($payload[$type]['filename']) && is_string($payload[$type]['filename'])) {
                $payload[$type]['filename'] = $this->jpegFilename($payload[$type]['filename']);
            }
        }

        if (isset($payload['filename']) && is_string($payload['filename'])) {
            $payload['filename'] = $this->jpegFilename($payload['filename']);
        }

        return $payload;
    }

    private function jpegFilename(string $filename): string
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        return ($basename !== '' ? $basename : 'image').'.jpg';
    }

    private function isHeicPath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['heic', 'heif', 'heics', 'heifs'], true);
    }

    private function mimeTypeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'heic', 'heics' => 'image/heic',
            'heif', 'heifs' => 'image/heif',
            default => 'application/octet-stream',
        };
    }

    private function cachedPath(Message $message, array $payload = []): ?string
    {
        $disk = $this->storageManager->disk();

        $storedPath = $payload['path'] ?? $payload[(string) $message->type]['path'] ?? null;
        if (is_string($storedPath) && $storedPath !== '' && $disk->exists($storedPath)) {
            return $storedPath;
        }

        $prefix = $this->storageManager->prefixedPath("message-media/{$message->id}");
        $files = $disk->files($this->storageManager->prefixedPath('message-media'));

        $matches = collect($files)->filter(fn ($file) => str_starts_with($file, $prefix));

        return $matches->first(fn ($file) => ! $this->isHeicPath($file)) ?? $matches->first();
    }

    private function hasResolvableMedia(Message $message, array $payload): bool
    {
        if (! in_array($message->type, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
            return false;
        }

        if (! empty($payload['preview_url'])) {
            return true;
        }

        $type = (string) $message->type;

        return ! empty($payload[$type]['id'])
            || ! empty($payload[$type]['url'])
            || ! empty($payload['media_id'])
            || ! empty($payload['_meta_attachment']['url']);
    }

    private function routedMediaUrl(Message $message, ?string $routeName): ?string
    {
        $payload = $message->payload ?? [];
        if (! $routeName || ! $this->hasResolvableMedia($message, $payload)) {
            return null;
        }

        if (! $message->relationLoaded('conversation')) {
            $message->loadMissing('conversation');
        }

        if (! $message->conversation) {
            return null;
        }

        return $this->routeMediaUrl($routeName, $message);
    }

    private function routeMediaUrl(string $routeName, Message $message): string
    {
        if ($routeName === 'api.v1.mobile.conversations.messages.media.signed') {
            return URL::temporarySignedRoute($routeName, now()->addMinutes(30), [
                'uuid' => $message->conversation->uuid,
                'message' => $message->id,
            ]);
        }

        return route($routeName, [
            'conversation' => $message->conversation->uuid,
            'message' => $message->id,
        ]);
    }

    private function applyMediaUrlToPayload(array $payload, string $type, string $mediaUrl, ?string $routeName): array
    {
        $payload['media_url'] = $mediaUrl;
        $payload['attachment_url'] = $mediaUrl;

        if ($this->routeShouldOwnPreview($routeName)) {
            $payload['preview_url'] = $mediaUrl;
            $payload['url'] = $mediaUrl;
            $payload['link'] = $mediaUrl;

            if (isset($payload[$type]) && is_array($payload[$type])) {
                $payload[$type]['url'] = $mediaUrl;
                $payload[$type]['preview_url'] = $mediaUrl;
                $payload[$type]['link'] = $mediaUrl;
            }
        }

        return $payload;
    }

    private function routeShouldOwnPreview(?string $routeName): bool
    {
        return in_array($routeName, [
            'api.v1.mobile.conversations.messages.media',
            'api.v1.mobile.conversations.messages.media.signed',
            'client.inbox.message-media',
        ], true);
    }

    private function streamCachedMedia(string $path, ?string $mimeType = null): Response
    {
        $disk = $this->storageManager->disk();
        $mimeType = $mimeType ?: ($disk->mimeType($path) ?: $this->mimeTypeFromPath($path));

        return response((string) $disk->get($path), 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function isGenericMediaBody(string $type, string $body): bool
    {
        if (! in_array($type, ['image', 'video', 'audio', 'sticker'], true)) {
            return false;
        }

        $plain = preg_replace('/^[^\p{L}\p{N}]+/u', '', trim($body)) ?: '';
        $plain = strtolower(trim($plain));

        return in_array($plain, match ($type) {
            'image' => ['image', 'image attachment'],
            'video' => ['video', 'video attachment'],
            'audio' => ['audio', 'voice message', 'audio attachment'],
            'sticker' => ['sticker'],
            default => [],
        }, true);
    }

    private function metaRemoteUrl(array $payload, string $type): ?string
    {
        if (($payload['_meta_attachment']['provider'] ?? null) !== 'meta') {
            return null;
        }

        foreach ([
            $payload[$type]['url'] ?? null,
            $payload['_meta_attachment']['url'] ?? null,
            $payload['url'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function extensionFromMime(string $mimeType): string
    {
        $mime = strtolower(trim(explode(';', $mimeType)[0]));

        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/heic', 'image/heic-sequence' => 'heic',
            'image/heif', 'image/heif-sequence' => 'heif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/aac' => 'm4a',
            'audio/ogg', 'application/ogg' => 'ogg',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function browserSafePublicUrl(string $url, Request $request): string
    {
        if (! str_starts_with(strtolower($url), 'http://')) {
            return $url;
        }

        $assetHost = parse_url($url, PHP_URL_HOST);
        $requestHost = $request->getHost();
        $shouldUseHttps = $request->isSecure() || app()->environment('production');

        if ($shouldUseHttps && $assetHost && strcasecmp($assetHost, $requestHost) === 0) {
            return 'https://'.substr($url, strlen('http://'));
        }

        return $url;
    }
}
