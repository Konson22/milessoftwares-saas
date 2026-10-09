<?php

namespace App\Modules\Identity\RelyingParty;

use RuntimeException;

/**
 * The OIDC callback could not be turned into a signed-in user. The message is
 * for logs only; users see a generic error.
 */
final class SignInFailed extends RuntimeException {}
