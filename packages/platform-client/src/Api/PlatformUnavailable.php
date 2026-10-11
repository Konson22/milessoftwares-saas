<?php

namespace Miles\PlatformClient\Api;

use RuntimeException;

/**
 * The platform could not be reached or answered with an unexpected error.
 */
final class PlatformUnavailable extends RuntimeException {}
