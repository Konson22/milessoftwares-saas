<?php

namespace Miles\PlatformClient\SignIn;

use RuntimeException;

/**
 * The sign-in could not be completed. The message is for logs, not users.
 */
final class SignInFailed extends RuntimeException {}
