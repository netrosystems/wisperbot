<?php

namespace App\Modules\Integrations\Services\Credentials;

class OAuthClientCredentials extends CredentialValueObject
{
    public function clientId(): ?string
    {
        return $this->get('client_id') ?? $this->get('client_key');
    }

    public function clientSecret(): ?string
    {
        return $this->get('client_secret');
    }

    /**
     * LinkedIn's Community Management API must be the only product on its app,
     * so Company Page posting uses a second LinkedIn app with its own keys.
     * Without those keys the connection stays personal-profile only.
     */
    public function allowsOrganizationPosting(): bool
    {
        return $this->has('pages_client_id') && $this->has('pages_client_secret');
    }

    public function pagesClientId(): ?string
    {
        return $this->get('pages_client_id');
    }

    public function pagesClientSecret(): ?string
    {
        return $this->get('pages_client_secret');
    }
}
