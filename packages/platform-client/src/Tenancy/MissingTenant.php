<?php

namespace Miles\PlatformClient\Tenancy;

use LogicException;

/**
 * A tenant-owned model was used without a current tenant. Fail closed:
 * never fall back to "all organizations".
 */
final class MissingTenant extends LogicException {}
