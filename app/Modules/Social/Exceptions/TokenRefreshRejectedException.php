<?php

namespace App\Modules\Social\Exceptions;

/**
 * The network refused the stored refresh token (revoked, expired or
 * invalidated), so only reconnecting the account helps. Anything else, such
 * as a timeout, a 5xx or a wrong platform client secret, is temporary from
 * the account's point of view and must not disconnect it.
 */
class TokenRefreshRejectedException extends \RuntimeException {}
