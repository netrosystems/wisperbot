<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class MessageMediaResolver
{
    public function __construct(private readonly StorageManager $storageManager) {}

    public function response(Message $message, Request $request): Response
    {
        $payload = $message->payload ?? [];
        $cached = $this->cachedPath($message);
        $disk = $this->storageManager->disk();

        if ($cached && $disk->exists($cached)) {
            return redirect($this->browserSafePublicUrl($disk->url($cached), $request));
        }

        $type = (string) ($message->type ?? 'image');

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
            $payload['media_url'] = $mediaUrl;
            $payload['attachment_url'] = $mediaUrl;
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
            $payload['media_url'] = $mediaUrl;
            $payload['attachment_url'] = $mediaUrl;
        }

        return $payload;
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
        $extension = $this->extensionFromMime($mimeType);
        $filename = $this->storageManager->prefixedPath("message-media/{$message->id}.{$extension}");
        $disk = $this->storageManager->disk();
        $disk->put($filename, $bytes);
        $previewUrl = $this->browserSafePublicUrl($disk->url($filename), $request);

        $message->update(['payload' => array_merge($payload, [
            'preview_url' => $previewUrl,
            'mime_type' => $mimeType,
        ])]);

        return redirect($previewUrl);
    }

    private function cachedPath(Message $message): ?string
    {
        $disk = $this->storageManager->disk();
        $prefix = $this->storageManager->prefixedPath("message-media/{$message->id}");
        $files = $disk->files($this->storageManager->prefixedPath('message-media'));

        return collect($files)->first(fn ($file) => str_starts_with($file, $prefix));
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
        $parameters = [
            'uuid' => $message->conversation->uuid,
            'message' => $message->id,
        ];

        if ($routeName === 'api.v1.mobile.conversations.messages.media.signed') {
            return URL::temporarySignedRoute($routeName, now()->addMinutes(30), $parameters);
        }

        return route($routeName, $parameters);
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
