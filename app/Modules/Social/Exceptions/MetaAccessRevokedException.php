<?php

namespace App\Modules\Social\Exceptions;

/**
 * Meta refused the Page token (error 190) or the permission it needs (10,
 * 200). Nothing was posted, and retrying cannot help until the account is
 * reconnected with the Page selected again.
 */
class MetaAccessRevokedException extends ClientSafePublishException
{
    /** Graph API error codes that mean the connection lost access. */
    public const CODES = [190, 10, 200];
}
