<?php

namespace Miles\PlatformClient\Api;

use RuntimeException;

/**
 * The platform answered 404: the organization or member does not exist, or
 * was never entitled to this application. Treat it as "no such tenant".
 */
final class NotVisible extends RuntimeException {}
