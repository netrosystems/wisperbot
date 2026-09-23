<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Exceptions\PublishOutcomeUnknownException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * For the one request that makes a post live. A timeout or a 5xx may still
 * have published it, so those become PublishOutcomeUnknownException and are
 * never retried automatically. A 4xx is a definite refusal.
 */
trait GuardsPublishOutcome
{
    /** @param  callable(): Response  $send */
    protected function createRequest(callable $send): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw new PublishOutcomeUnknownException($this->network(), $e);
        }

        if ($response->serverError()) {
            throw new PublishOutcomeUnknownException($this->network(), new \RuntimeException('HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500)));
        }

        return $response;
    }

    /** The created object's id; a success without one is an unknown outcome. */
    protected function createdId(Response $response, string $key = 'id'): string
    {
        if (! $response->successful()) {
            throw new \RuntimeException(ucfirst($this->network()).' publish failed (HTTP '.$response->status().'): '.mb_substr($response->body(), 0, 500));
        }

        $id = $response->json($key);
        if (! is_string($id) || $id === '') {
            throw new PublishOutcomeUnknownException($this->network(), new \RuntimeException('Success response without an id.'));
        }

        return $id;
    }
}
