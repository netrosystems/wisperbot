<?php

namespace App\Modules\Social\Exceptions;

/**
 * A definite publish failure (nothing was posted) whose message is written
 * for clients, for example a media file the network cannot accept. The
 * publisher shows it instead of the generic "see logs" message.
 */
class ClientSafePublishException extends \RuntimeException {}
