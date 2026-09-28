<?php

namespace App\Modules\Integrations\Services\Credentials;

class MetaCredentials extends CredentialValueObject
{
    public function appId(): ?string
    {
        return $this->get('app_id');
    }

    public function appSecret(): ?string
    {
        return $this->get('app_secret');
    }

    public function systemUserToken(): ?string
    {
        return $this->get('system_user_token');
    }

    public function verifyToken(): ?string
    {
        return $this->get('verify_token');
    }

    public function configIdWhatsapp(): ?string
    {
        return $this->get('config_id_whatsapp') ?: null;
    }

    public function configIdSocial(): ?string
    {
        return $this->get('config_id_social') ?: null;
    }

    /**
     * Login for Business configuration used by Social Media Automation. Its
     * business tokens belong to each connection, so connecting one Page never
     * removes access to another. Without it the personal Facebook login is used.
     */
    public function configIdPublishing(): ?string
    {
        return $this->get('config_id_publishing') ?: null;
    }
}
